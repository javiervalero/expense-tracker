<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A response as plain data: status line, headers and body. Whoever owns the transport (public/index.php under
 * php-fpm, later the Lambda entrypoint) turns it into output.
 *
 * Phase 0 shape, temporary: one value per header name, so repeated headers (several Set-Cookie, for instance) cannot
 * be expressed. Widen `headers` to a list of values per name before the first endpoint needs that.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
