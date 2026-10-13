<?php

declare(strict_types=1);

namespace App\Http;

/**
 * An incoming request as immutable data. `path` is the raw path (still percent-encoded, no query string);
 * `query` is already parsed by {@see QueryString}; header names are lower-case; `attributes` carry values that
 * middleware computed (request id, authenticated user, route parameters). Query and header names that are
 * all digits become integer keys in a PHP array (`?1=x`), hence the `array-key` types; look them up with a
 * string or an int, both work.
 */
final readonly class Request
{
    /** Attribute holding the decoded route parameters (`array<string, string>`), set by the routing middleware. */
    public const ROUTE_PARAMS = 'route.params';

    /** Attribute holding the request id (16 hex characters) that the kernel generates for each request. */
    public const REQUEST_ID = 'request.id';

    /**
     * @param array<array-key, string> $query
     * @param array<array-key, string> $headers lower-case names
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
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return $this->withAttributes([$name => $value]);
    }

    /**
     * A copy with several attributes added; a name that already exists is overwritten.
     *
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->headers,
            $this->body,
            $attributes + $this->attributes,
        );
    }
}
