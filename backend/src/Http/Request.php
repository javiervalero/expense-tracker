<?php

declare(strict_types=1);

namespace App\Http;

/**
 * An incoming request as immutable data. `path` is the raw path (still percent-encoded, no query string);
 * `query` is already parsed by {@see QueryString}; header names are lower-case; `attributes` carry values that
 * middleware computed (request id, authenticated user, route parameters).
 */
final readonly class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers lower-case names
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $headers,
        public string $body,
        private array $attributes = [],
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->headers,
            $this->body,
            [$name => $value] + $this->attributes,
        );
    }
}
