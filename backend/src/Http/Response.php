<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Problem\ProblemDetails;
use InvalidArgumentException;
use JsonException;

/**
 * A response as immutable data: status, headers and body. Whoever owns the transport (see
 * {@see ResponseEmitter}) turns it into output. A header name maps to a list of values so that repeated
 * headers (several Set-Cookie) can be expressed; names keep the casing they were given and are matched
 * case-insensitively; two names that differ only in case are rejected. Names and values are checked so a response
 * can never carry an injected header line (no control characters other than tab in a value). `json()` and
 * `problem()` own the Content-Type: a Content-Type passed in their `$headers` is replaced, not duplicated.
 */
final readonly class Response
{
    private const MIN_STATUS = 100;
    private const MAX_STATUS = 599;
    private const UNSAFE_VALUE = '/[\x00-\x08\x0A-\x1F\x7F]/';
    private const CONTENT_TYPE = 'Content-Type';
    private const TOKEN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

    /** @var array<array-key, list<string>> */
    public array $headers;

    /**
     * @param array<array-key, array<array-key, string>> $headers
     */
    public function __construct(
        public int $status,
        array $headers,
        public string $body,
    ) {
        if ($status < self::MIN_STATUS || $status > self::MAX_STATUS) {
            throw new InvalidArgumentException('Invalid HTTP status.');
        }
        $normalised = [];
        $seen = [];
        foreach ($headers as $name => $values) {
            $name = (string) $name;
            $lower = strtolower($name);
            if (isset($seen[$lower])) {
                throw new InvalidArgumentException('Header names must be unique ignoring case.');
            }
            $seen[$lower] = true;
            foreach ($values as $value) {
                self::assertSafe($name, $value);
            }
            $normalised[$name] = array_values($values);
        }
        $this->headers = $normalised;
    }

    /**
     * @param array<array-key, list<string>> $headers
     *
     * @throws JsonException
     */
    public static function json(int $status, mixed $data, array $headers = []): self
    {
        return new self(
            $status,
            self::withContentType('application/json', $headers),
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<array-key, list<string>> $headers
     */
    public static function empty(int $status, array $headers = []): self
    {
        return new self($status, $headers, '');
    }

    /**
     * Invalid UTF-8 in the problem is replaced by U+FFFD so an error response is still produced; it can still
     * throw for what cannot be encoded at all (NAN, INF, too deep extension members).
     *
     * @param array<array-key, list<string>> $headers
     *
     * @throws JsonException
     */
    public static function problem(ProblemDetails $problem, array $headers = []): self
    {
        return new self(
            $problem->status,
            self::withContentType(ProblemDetails::CONTENT_TYPE, $headers),
            json_encode($problem->toArray(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /**
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        foreach ($this->headers as $existing => $values) {
            if (self::sameName($existing, $name)) {
                return $values;
            }
        }

        return [];
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->withoutHeader($name) + [$name => [$value]], $this->body);
    }

    public function withAddedHeader(string $name, string $value): self
    {
        $existing = $this->headerValues($name);
        if ($existing === []) {
            return $this->withHeader($name, $value);
        }
        $headers = [];
        foreach ($this->headers as $key => $values) {
            $headers[$key] = self::sameName($key, $name) ? [...$values, $value] : $values;
        }

        return new self($this->status, $headers, $this->body);
    }

    public function withBody(string $body): self
    {
        return new self($this->status, $this->headers, $body);
    }

    /**
     * @return array<array-key, list<string>>
     */
    private function withoutHeader(string $name): array
    {
        return self::withoutName($name, $this->headers);
    }

    /**
     * @param array<array-key, list<string>> $headers
     *
     * @return array<array-key, list<string>>
     */
    private static function withoutName(string $name, array $headers): array
    {
        return array_filter(
            $headers,
            static fn(int|string $key): bool => !self::sameName($key, $name),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<array-key, list<string>> $headers
     *
     * @return array<array-key, list<string>>
     */
    private static function withContentType(string $contentType, array $headers): array
    {
        return [self::CONTENT_TYPE => [$contentType]] + self::withoutName(self::CONTENT_TYPE, $headers);
    }

    /** Header names are compared ignoring case; a digits-only name is an int key in a PHP array. */
    private static function sameName(int|string $existing, string $name): bool
    {
        return strcasecmp((string) $existing, $name) === 0;
    }

    private static function assertSafe(string $name, string $value): void
    {
        if (preg_match(self::TOKEN, $name) !== 1 || preg_match(self::UNSAFE_VALUE, $value) !== 0) {
            throw new InvalidArgumentException('Invalid header name or value.');
        }
    }
}
