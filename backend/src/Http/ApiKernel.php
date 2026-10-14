<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Problem\HttpProblem;
use App\Http\Middleware\RequestLoggingMiddleware;
use App\Http\Problem\ProblemDetails;
use App\Infrastructure\Logging\JsonLogger;
use Closure;
use Throwable;

/**
 * Entry point of one HTTP request, whatever the transport: gives the request an id, runs the handler (the
 * middleware pipeline) and puts the default headers on EVERY response (404, 405 and 500 included). It never
 * throws: the last resort is a generic 500 problem, and what went wrong is logged (class, a short message, file,
 * line and request id; never the trace) if a logger was given.
 */
final readonly class ApiKernel
{
    private const REQUEST_ID_BYTES = 8;
    private const MAX_MESSAGE_BYTES = 1024;

    /** @var Closure(): string */
    private Closure $requestIds;

    /**
     * @param (Closure(): string)|null $requestIds request id generator; random 16 hex characters when omitted
     * @param JsonLogger|null $logger where the last-resort failures and the requests rejected before the pipeline
     *                                are logged; nothing is logged when omitted
     */
    public function __construct(private Handler $handler, ?Closure $requestIds = null, private ?JsonLogger $logger = null)
    {
        $this->requestIds = $requestIds ?? static fn(): string => bin2hex(random_bytes(self::REQUEST_ID_BYTES));
    }

    public function handle(Request $request): Response
    {
        return $this->dispatch($request, ($this->requestIds)());
    }

    /**
     * Builds the request from PHP's `$_SERVER` and the body, then handles it. A request that cannot even be built
     * (a body over the JSON limit, which the caller reads one byte past; a malformed query string) gets its problem
     * here, because it never reaches the pipeline; it still gets its request line in the log.
     *
     * @param array<array-key, mixed> $server
     */
    public function handleGlobals(array $server, string $body): Response
    {
        $requestId = ($this->requestIds)();
        try {
            if (strlen($body) > JsonBody::MAX_BYTES) {
                throw HttpProblem::of(413, 'The request body is larger than ' . JsonBody::MAX_BYTES . ' bytes.');
            }
            $request = RequestFactory::fromGlobals($server, $body);
        } catch (HttpProblem $problem) {
            return $this->reject(RequestFactory::bare($server), $requestId, $problem);
        }

        return $this->dispatch($request, $requestId);
    }

    private function dispatch(Request $request, string $requestId): Response
    {
        try {
            $response = $this->handler->handle($request->withAttribute(Request::REQUEST_ID, $requestId));
        } catch (Throwable $error) {
            $this->logFailure($error, $requestId);
            $response = self::internalError();
        }

        return self::withDefaultHeaders($response);
    }

    private function reject(Request $request, string $requestId, HttpProblem $problem): Response
    {
        $this->logRejection($request->withAttribute(Request::REQUEST_ID, $requestId), $problem);

        try {
            $response = Response::problem($problem->problem, $problem->headers);
            // @codeCoverageIgnoreStart
            // The problems raised above are fixed, encodable values; this only keeps "never throws" true.
        } catch (Throwable $error) {
            $this->logFailure($error, $requestId);
            $response = self::internalError();
        }
        // @codeCoverageIgnoreEnd

        return self::withDefaultHeaders($response);
    }

    private function logRejection(Request $request, HttpProblem $problem): void
    {
        if ($this->logger === null) {
            return;
        }
        $rejected = new class ($problem) implements Handler {
            public function __construct(private HttpProblem $problem) {}

            public function handle(Request $request): Response
            {
                throw $this->problem;
            }
        };
        try {
            (new RequestLoggingMiddleware($this->logger))->process($request, $rejected);
        } catch (HttpProblem) {
            // Expected: the logging middleware rethrows after writing the line.
        } catch (Throwable) {
            // A broken log must not break the answer.
        }
    }

    private function logFailure(Throwable $error, string $requestId): void
    {
        try {
            $this->logger?->error('request failed past the error handler', [
                'requestId' => $requestId,
                'exception' => $error::class,
                'exceptionMessage' => mb_strcut($error->getMessage(), 0, self::MAX_MESSAGE_BYTES, 'UTF-8'),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ]);
        } catch (Throwable) {
            // A broken log must not break the answer.
        }
    }

    /**
     * The constant answer for anything that goes wrong before or outside the kernel (see the front controller),
     * with the same default headers as every other response.
     */
    public static function internalErrorResponse(): Response
    {
        return self::withDefaultHeaders(self::internalError());
    }

    private static function internalError(): Response
    {
        return Response::problem(ProblemDetails::forStatus(500));
    }

    private static function withDefaultHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
