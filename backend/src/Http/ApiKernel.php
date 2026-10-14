<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Problem\HttpProblem;
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
    private const FALLBACK_REQUEST_ID = 'unknown';

    /** @var Closure(): string */
    private Closure $requestIds;

    /**
     * @param (Closure(): string)|null $requestIds request id generator; random 16 hex characters when omitted
     * @param JsonLogger|null $logger where the last-resort failures are logged; nothing is logged when omitted
     */
    public function __construct(private Handler $handler, ?Closure $requestIds = null, private ?JsonLogger $logger = null)
    {
        $this->requestIds = $requestIds ?? static fn(): string => bin2hex(random_bytes(self::REQUEST_ID_BYTES));
    }

    public function handle(Request $request): Response
    {
        return $this->dispatch($request, $this->newRequestId());
    }

    /**
     * Builds the request from PHP's `$_SERVER` and the body, then handles it. A request that cannot even be built
     * (a body over the JSON limit, which the caller reads one byte past; a malformed query string) still goes through
     * the pipeline as a bare request (method, path, headers) carrying its problem as {@see Request::DEFERRED_PROBLEM},
     * so the middleware (the origin check, the request line) treat it like any other and the problem is raised
     * where the pipeline decides.
     *
     * @param array<array-key, mixed> $server
     */
    public function handleGlobals(array $server, string $body): Response
    {
        $requestId = $this->newRequestId();
        try {
            if (strlen($body) > JsonBody::MAX_BYTES) {
                throw HttpProblem::of(413, 'The request body is larger than ' . JsonBody::MAX_BYTES . ' bytes.');
            }
            $request = RequestFactory::fromGlobals($server, $body);
        } catch (HttpProblem $problem) {
            $request = RequestFactory::bare($server)->withAttribute(Request::DEFERRED_PROBLEM, $problem);
        }

        return $this->dispatch($request, $requestId);
    }

    /**
     * A broken generator (no entropy, a bad injected closure) must not break the "never throws" promise.
     */
    private function newRequestId(): string
    {
        try {
            return ($this->requestIds)();
        } catch (Throwable) {
            return self::FALLBACK_REQUEST_ID;
        }
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

    private function logFailure(Throwable $error, string $requestId): void
    {
        try {
            $this->logger?->error('request failed past the error handler', [
                'requestId' => $requestId,
                'exception' => $error::class,
                'exceptionMessage' => $this->logger?->capMessage($error->getMessage()),
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
