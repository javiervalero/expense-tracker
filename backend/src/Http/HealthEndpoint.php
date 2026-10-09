<?php

declare(strict_types=1);

namespace App\Http;

/**
 * TEMPORARY (Phase 0, WP12). The only route of the API skeleton: it proves that nginx, php-fpm and
 * public/index.php work together. Phase 1's router, middleware and problem-details library replace it; do not
 * grow it into a router.
 *
 * Pure: (method, path) in, a response value out. The path is the URL path without the query string.
 */
final class HealthEndpoint
{
    private const HEALTH_PATH = '/api/v1/health';
    private const ALLOWED_METHODS = 'GET, HEAD';

    /** Sent with every response: nothing here may be cached, and the declared type is the type. */
    private const COMMON_HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function handle(string $method, string $path): HttpResponse
    {
        if ($path !== self::HEALTH_PATH) {
            return $this->problem(404, 'Not Found');
        }

        return match ($method) {
            'GET' => $this->ok('{"status":"ok"}'),
            'HEAD' => $this->ok(''),
            default => $this->problem(405, 'Method Not Allowed', ['Allow' => self::ALLOWED_METHODS]),
        };
    }

    private function ok(string $body): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'] + self::COMMON_HEADERS, $body);
    }

    /**
     * RFC 9457 problem details, minimal: `type` is "about:blank", so the title is the status phrase.
     *
     * @param array<string, string> $extraHeaders
     */
    private function problem(int $status, string $title, array $extraHeaders = []): HttpResponse
    {
        $body = json_encode(['type' => 'about:blank', 'title' => $title, 'status' => $status], JSON_THROW_ON_ERROR);

        return new HttpResponse(
            $status,
            ['Content-Type' => 'application/problem+json'] + $extraHeaders + self::COMMON_HEADERS,
            $body,
        );
    }
}
