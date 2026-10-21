<?php

declare(strict_types=1);

namespace App\Auth\Session;

use JsonSerializable;
use LogicException;
use WeakMap;

/**
 * The secret in the session cookie (A2): 32 random bytes as 43 base64url characters. Only its SHA-256 hex
 * ({@see self::hash()}) is ever stored.
 *
 * The value never leaves through a debugging or serialising path. It is not a property of the object but an entry
 * in a static WeakMap (freed with the object), so `var_export`, `(array)` casts, `get_object_vars` and
 * `debug_zval_dump` find nothing; `var_dump` and `print_r` use `__debugInfo`; `json_encode` and `serialize`
 * throw; there is no `__toString`; the object cannot be cloned. Read the value deliberately with
 * {@see self::cookieValue()}, and only to build the Set-Cookie header.
 */
final class SessionToken implements JsonSerializable
{
    public const LENGTH = 43;

    private const BYTES = 32;
    private const FORMAT = '/\A[A-Za-z0-9_-]{' . self::LENGTH . '}\z/D';

    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    private function __construct(#[\SensitiveParameter] string $value)
    {
        self::values()[$this] = $value;
    }

    public static function generate(): self
    {
        $value = rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');

        return new self($value);
    }

    /** Null unless the value is exactly 43 characters of A-Z, a-z, 0-9, `-` and `_`. */
    public static function fromCookieValue(#[\SensitiveParameter] string $value): ?self
    {
        return preg_match(self::FORMAT, $value) === 1 ? new self($value) : null;
    }

    /** SHA-256 as 64 lower-case hex characters. */
    public function hash(): string
    {
        return hash('sha256', $this->cookieValue());
    }

    public function cookieValue(): string
    {
        return self::values()[$this] ?? throw new LogicException('A session token has no value.');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('A session token must not be encoded as JSON.');
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A session token must not be serialised.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new LogicException('A session token must not be unserialised.');
    }

    public function __clone()
    {
        throw new LogicException('A session token must not be cloned.');
    }

    /**
     * @return WeakMap<self, string>
     */
    private static function values(): WeakMap
    {
        return self::$values ??= new WeakMap();
    }
}
