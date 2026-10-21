<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;

/**
 * Cross-site request forgery check (plan rule A8). `GET`, `HEAD` and `OPTIONS` pass. Every other method needs both:
 *
 *  - `X-Requested-With: XMLHttpRequest`, the exact value: a plain cross-site form cannot set it, and a cross-site
 *    `fetch` that sets it needs a CORS preflight, which this API never grants;
 *  - `Origin`, byte for byte one of the configured origins. A missing or `null` Origin is refused: a `fetch` in the
 *    default `cors` mode always sends it on these methods, so the app never lacks it. Nothing is trimmed, lowered or
 *    split on commas, so `a, b`, padded and case-changed values fail.
 *
 * It runs before authentication, so a forged request costs no database read. Neither value is a secret, but the
 * Origin is client-controlled, so it is capped before it is logged. The answer is one generic 403 whatever the reason.
 */
final readonly class CsrfMiddleware implements Middleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const KNOWN_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const UNKNOWN_METHOD = 'OTHER';
    private const REQUESTED_WITH = 'x-requested-with';
    private const REQUESTED_WITH_VALUE = 'XMLHttpRequest';
    private const ORIGIN = 'origin';
    private const MAX_PATH_LENGTH = 256;
    private const MAX_ORIGIN_LENGTH = 128;
    private const DETAIL = 'The request failed the cross-site request check.';

    /**
     * @param list<string> $allowedOrigins the browser origins of the app (1 to 3, validated by ApiConfig)
     */
    public function __construct(private JsonLogger $logger, private array $allowedOrigins) {}

    public function process(Request $request, Handler $next): Response
    {
        $method = strtoupper($request->method);
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $next->handle($request);
        }
        $origin = $request->header(self::ORIGIN) ?? '';
        $reason = $this->refusalReason($request->header(self::REQUESTED_WITH), $origin);
        if ($reason === null) {
            return $next->handle($request);
        }
        $this->logger->warning('csrf check failed', [
            'requestId' => $request->attribute(Request::REQUEST_ID),
            'method' => in_array($method, self::KNOWN_METHODS, true) ? $method : self::UNKNOWN_METHOD,
            'path' => mb_strcut($request->path, 0, self::MAX_PATH_LENGTH, 'UTF-8'),
            'reason' => $reason,
            'origin' => mb_strcut($origin, 0, self::MAX_ORIGIN_LENGTH, 'UTF-8'),
        ]);

        throw HttpProblem::of(403, self::DETAIL);
    }

    private function refusalReason(?string $requestedWith, string $origin): ?string
    {
        if ($requestedWith === null) {
            return 'missing-header';
        }
        if ($requestedWith !== self::REQUESTED_WITH_VALUE) {
            return 'bad-header';
        }
        if ($origin === '') {
            return 'missing-origin';
        }

        return in_array($origin, $this->allowedOrigins, true) ? null : 'foreign-origin';
    }
}
