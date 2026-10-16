<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * Writes a {@see Response} to the transport through three injected writers, so it is testable without a web
 * server. A HEAD request gets the status and headers but no body.
 */
final readonly class ResponseEmitter
{
    /**
     * @param Closure(int): void $statusWriter
     * @param Closure(string, bool): void $headerWriter receives "Name: value" and whether it replaces earlier ones
     * @param Closure(string): void $bodyWriter
     */
    public function __construct(
        private Closure $statusWriter,
        private Closure $headerWriter,
        private Closure $bodyWriter,
    ) {}

    /** The real thing, for `public/index.php`: PHP's own `http_response_code()`, `header()` and `echo`. */
    public static function native(): self
    {
        return new self(
            static function (int $status): void {
                http_response_code($status);
            },
            static function (string $line, bool $replace): void {
                header($line, $replace);
            },
            static function (string $chunk): void {
                echo $chunk;
            },
        );
    }

    public function emit(Response $response, string $requestMethod): void
    {
        ($this->statusWriter)($response->status);
        foreach ($response->headers as $name => $values) {
            foreach ($values as $index => $value) {
                ($this->headerWriter)($name . ': ' . $value, $index === 0);
            }
        }
        if ($requestMethod !== 'HEAD' && $response->body !== '') {
            ($this->bodyWriter)($response->body);
        }
    }
}
