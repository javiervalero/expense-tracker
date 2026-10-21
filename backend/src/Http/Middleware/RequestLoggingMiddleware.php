<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use Closure;
use Throwable;

/**
 * One JSON log line per request: request id, method, path (no query string, at most 256 bytes), status and
 * duration. Headers, cookies, query values and the body are never logged. If something below throws, the line
 * says 500 (the error middleware outside this one answers that way) and the exception goes on; an HttpProblem is
 * logged with its own status. The method is logged only if it is a known one, else "OTHER". `clientIp` is the
 * address that ClientIpMiddleware recorded in the {@see RequestLogContext} this middleware gives the request, or
 * "unknown" when it recorded none: also for a request that was rejected before ClientIp ran (origin check, deferred
 * 413/400), whose header is not trusted yet and is never read here. `userId` (an opaque UUID, never the e-mail) is
 * the user that a session guard or a login recorded in the same context, or null.
 */
final readonly class RequestLoggingMiddleware implements Middleware
{
    private const MAX_PATH_LENGTH = 256;
    private const KNOWN_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const UNKNOWN_METHOD = 'OTHER';
    private const UNKNOWN_CLIENT_IP = 'unknown';
    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;
    private const DURATION_DECIMALS = 3;

    /** @var Closure(): int */
    private Closure $nanoseconds;

    /**
     * @param (Closure(): int)|null $nanoseconds a monotonic clock; hrtime(true) when omitted
     */
    public function __construct(private JsonLogger $logger, ?Closure $nanoseconds = null)
    {
        $this->nanoseconds = $nanoseconds ?? static fn(): int => hrtime(true);
    }

    public function process(Request $request, Handler $next): Response
    {
        $start = ($this->nanoseconds)();
        $context = new RequestLogContext();
        try {
            $response = $next->handle($request->withAttribute(RequestLogContext::ATTRIBUTE, $context));
        } catch (HttpProblem $problem) {
            $this->log($request, $context, $problem->problem->status, $start);

            throw $problem;
        } catch (Throwable $error) {
            $this->log($request, $context, 500, $start);

            throw $error;
        }
        $this->log($request, $context, $response->status, $start);

        return $response;
    }

    private function log(Request $request, RequestLogContext $context, int $status, int $start): void
    {
        $this->logger->info('request', [
            'requestId' => $request->attribute(Request::REQUEST_ID),
            'method' => in_array($request->method, self::KNOWN_METHODS, true) ? $request->method : self::UNKNOWN_METHOD,
            'path' => mb_strcut($request->path, 0, self::MAX_PATH_LENGTH, 'UTF-8'),
            'clientIp' => $context->clientIp() ?? self::UNKNOWN_CLIENT_IP,
            'userId' => $context->userId(),
            'status' => $status,
            'durationMs' => round(
                (($this->nanoseconds)() - $start) / self::NANOSECONDS_PER_MILLISECOND,
                self::DURATION_DECIMALS,
            ),
        ]);
    }
}
