<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use Closure;
use Throwable;

/**
 * One JSON log line per request: request id, method, path (no query string, at most 256 characters), status and
 * duration. Headers, cookies, query values and the body are never logged. If something below throws, the line
 * says 500 (the error middleware outside this one answers that way) and the exception goes on.
 */
final readonly class RequestLoggingMiddleware implements Middleware
{
    private const MAX_PATH_LENGTH = 256;
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
        try {
            $response = $next->handle($request);
        } catch (Throwable $error) {
            $this->log($request, 500, $start);

            throw $error;
        }
        $this->log($request, $response->status, $start);

        return $response;
    }

    private function log(Request $request, int $status, int $start): void
    {
        $this->logger->info('request', [
            'requestId' => $request->attribute(Request::REQUEST_ID),
            'method' => $request->method,
            'path' => mb_strcut($request->path, 0, self::MAX_PATH_LENGTH, 'UTF-8'),
            'status' => $status,
            'durationMs' => round(
                (($this->nanoseconds)() - $start) / self::NANOSECONDS_PER_MILLISECOND,
                self::DURATION_DECIMALS,
            ),
        ]);
    }
}
