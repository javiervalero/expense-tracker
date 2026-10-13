<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Problem\HttpProblem;
use App\Http\Problem\ProblemDetails;
use Closure;
use Throwable;

/**
 * Entry point of one HTTP request, whatever the transport: gives the request an id, runs the handler (the
 * middleware pipeline) and puts the default headers on EVERY response (404, 405 and 500 included). It never
 * throws: the last resort is a generic 500 problem.
 */
final readonly class ApiKernel
{
    private const REQUEST_ID_BYTES = 8;

    /** @var Closure(): string */
    private Closure $requestIds;

    /**
     * @param (Closure(): string)|null $requestIds request id generator; random 16 hex characters when omitted
     */
    public function __construct(private Handler $handler, ?Closure $requestIds = null)
    {
        $this->requestIds = $requestIds ?? static fn(): string => bin2hex(random_bytes(self::REQUEST_ID_BYTES));
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->handler->handle($request->withAttribute(Request::REQUEST_ID, ($this->requestIds)()));
        } catch (Throwable) {
            $response = self::internalError();
        }

        return self::withDefaultHeaders($response);
    }

    /**
     * Builds the request from PHP's `$_SERVER` and the (already size-capped) body, then handles it. A request that
     * cannot even be built (malformed query string) gets its problem here, because it never reaches the pipeline.
     *
     * @param array<array-key, mixed> $server
     */
    public function handleGlobals(array $server, string $body): Response
    {
        try {
            $request = RequestFactory::fromGlobals($server, $body);
        } catch (HttpProblem $problem) {
            return self::withDefaultHeaders(self::renderable($problem));
        }

        return $this->handle($request);
    }

    private static function renderable(HttpProblem $problem): Response
    {
        try {
            return Response::problem($problem->problem, $problem->headers);
            // @codeCoverageIgnoreStart
            // The request factory's problems are fixed, encodable values; this only keeps "never throws" true.
        } catch (Throwable) {
            return self::internalError();
        }
        // @codeCoverageIgnoreEnd
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
