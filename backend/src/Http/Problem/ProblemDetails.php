<?php

declare(strict_types=1);

namespace App\Http\Problem;

use InvalidArgumentException;

/**
 * RFC 9457 problem details as a value. `type` is "about:blank" unless a more specific problem type is
 * named; then `title` is the status phrase. Extension members must not reuse a standard member name.
 * Sent as `application/problem+json`.
 */
final readonly class ProblemDetails
{
    public const CONTENT_TYPE = 'application/problem+json';

    private const DEFAULT_TYPE = 'about:blank';
    private const MIN_ERROR_STATUS = 400;
    private const MAX_ERROR_STATUS = 599;
    private const RESERVED_MEMBERS = ['type', 'title', 'status', 'detail', 'instance'];
    private const FALLBACK_TITLE = 'Error';
    private const TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    /**
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public int $status,
        public string $title,
        public string $type = self::DEFAULT_TYPE,
        public ?string $detail = null,
        public ?string $instance = null,
        public array $extensions = [],
    ) {
        if ($status < self::MIN_ERROR_STATUS || $status > self::MAX_ERROR_STATUS) {
            throw new InvalidArgumentException('A problem has an error status (4xx or 5xx).');
        }
        foreach (self::RESERVED_MEMBERS as $name) {
            if (array_key_exists($name, $extensions)) {
                throw new InvalidArgumentException('An extension member cannot reuse the name "' . $name . '".');
            }
        }
    }

    public static function forStatus(int $status, ?string $detail = null, ?string $instance = null): self
    {
        return new self(
            status: $status,
            title: self::TITLES[$status] ?? self::FALLBACK_TITLE,
            detail: $detail,
            instance: $instance,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $members = ['type' => $this->type, 'title' => $this->title, 'status' => $this->status];
        if ($this->detail !== null) {
            $members['detail'] = $this->detail;
        }
        if ($this->instance !== null) {
            $members['instance'] = $this->instance;
        }

        return $members + $this->extensions;
    }
}
