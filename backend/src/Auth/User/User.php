<?php

declare(strict_types=1);

namespace App\Auth\User;

use App\Auth\Email;
use App\Infrastructure\Clock\Clock;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use WeakMap;

/**
 * A registered user. The password hash is a secret and is not a property of the object but an entry in a static
 * WeakMap (freed with the user), like the value of a SessionToken: `var_export`, `(array)` casts and
 * `get_object_vars` find only the other fields; `var_dump` and `print_r` use `__debugInfo`; `json_encode`,
 * `serialize` and `unserialize` throw; the object cannot be cloned. Read the hash deliberately with
 * {@see self::passwordHash()}. Only an Argon2id hash is accepted ({@see self::isAcceptableHash()}).
 */
final class User implements JsonSerializable
{
    private const HASH_PREFIX = '$argon2id$';

    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $hashes = null;

    /**
     * @throws InvalidArgumentException when the hash is empty or not an Argon2id hash (the message never holds it)
     */
    public function __construct(
        public readonly string $userId,
        public readonly Email $email,
        #[\SensitiveParameter]
        string $passwordHash,
        public readonly DateTimeImmutable $createdAt,
    ) {
        if (!self::isAcceptableHash($passwordHash)) {
            throw new InvalidArgumentException('A password hash must be an Argon2id hash.');
        }
        self::hashes()[$this] = $passwordHash;
    }

    /**
     * @throws InvalidArgumentException when the hash is empty or not an Argon2id hash
     */
    public static function register(string $userId, Email $email, #[\SensitiveParameter] string $passwordHash, Clock $clock): self
    {
        return new self($userId, $email, $passwordHash, $clock->now());
    }

    /** True for a string that starts with `$argon2id$`: nothing else is stored or read back as a hash. */
    public static function isAcceptableHash(#[\SensitiveParameter] string $passwordHash): bool
    {
        return str_starts_with($passwordHash, self::HASH_PREFIX);
    }

    public function passwordHash(): string
    {
        return self::hashes()[$this] ?? throw new LogicException('A user has no password hash.');
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'email' => $this->email->value(), 'passwordHash' => '[redacted]', 'createdAt' => $this->createdAt];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('A user must not be encoded as JSON.');
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A user must not be serialised.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new LogicException('A user must not be unserialised.');
    }

    public function __clone()
    {
        throw new LogicException('A user must not be cloned.');
    }

    /**
     * @return WeakMap<self, string>
     */
    private static function hashes(): WeakMap
    {
        return self::$hashes ??= new WeakMap();
    }
}
