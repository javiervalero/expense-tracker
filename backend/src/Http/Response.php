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
 * case-insensitively. Names and values are checked so a response can never carry an injected header line.
 */
final readonly class Response
{
    private const MIN_STATUS = 100;
    private const MAX_STATUS = 599;
    private const TOKEN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/";

    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
        if ($status < self::MIN_STATUS || $status > self::MAX_STATUS) {
            throw new InvalidArgumentException('Invalid HTTP status.');
        }
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                self::assertSafe($name, $value);
            }
        }
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @throws JsonException
     */
    public static function json(int $status, mixed $data, array $headers = []): self
    {
        return new self(
            $status,
            ['Content-Type' => ['application/json']] + $headers,
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public static function empty(int $status, array $headers = []): self
    {
        return new self($status, $headers, '');
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public static function problem(ProblemDetails $problem, array $headers = []): self
    {
        return new self(
            $problem->status,
            ['Content-Type' => [ProblemDetails::CONTENT_TYPE]] + $headers,
            json_encode($problem->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        foreach ($this->headers as $existing => $values) {
            if (strcasecmp($existing, $name) === 0) {
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
            $headers[$key] = strcasecmp($key, $name) === 0 ? [...$values, $value] : $values;
        }

        return new self($this->status, $headers, $this->body);
    }

    public function withBody(string $body): self
    {
        return new self($this->status, $this->headers, $body);
    }

    /**
     * @return array<string, list<string>>
     */
    private function withoutHeader(string $name): array
    {
        return array_filter(
            $this->headers,
            static fn(string $key): bool => strcasecmp($key, $name) !== 0,
            ARRAY_FILTER_USE_KEY,
        );
    }

    private static function assertSafe(string $name, string $value): void
    {
        if (preg_match(self::TOKEN, $name) !== 1 || strpbrk($value, "\r\n\0") !== false) {
            throw new InvalidArgumentException('Invalid header name or value.');
        }
    }
}
