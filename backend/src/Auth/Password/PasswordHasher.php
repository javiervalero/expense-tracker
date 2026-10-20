<?php

declare(strict_types=1);

namespace App\Auth\Password;

/**
 * Argon2id hashing (A12, A16). The production cost is pinned in constants (PHP's defaults today) so a PHP upgrade
 * cannot change it silently; Bref's libsodium accepts only one thread. The parameters are constructor arguments so
 * unit tests can use cheap ones.
 *
 * Passwords are compared byte for byte: there is no Unicode normalisation (NFKC), because the `intl` extension is in
 * neither the tools image nor the Bref image. Production code uses only {@see self::production()}; an architecture
 * test forbids `new PasswordHasher` elsewhere in src/.
 */
final class PasswordHasher implements PasswordVerifier
{
    public const MEMORY_COST = 65536;
    public const TIME_COST = 4;
    public const THREADS = 1;

    /**
     * An Argon2id hash with the production parameters, made once from a random value that was thrown away.
     * Login verifies against it when the email has no account, so known and unknown emails cost the same time.
     * A test pins its parameters to the constants above: regenerate it when they change.
     */
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$WS9oeVg0N2duMC5vQkdjRg$7xyuys556eq7R3j7na/0Jvv5BRng8OjCbpW2y3llSEE';

    public function __construct(
        private readonly int $memoryCost,
        private readonly int $timeCost,
        private readonly int $threads,
    ) {}

    public static function production(): self
    {
        return new self(self::MEMORY_COST, self::TIME_COST, self::THREADS);
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** True when the hash is not Argon2id with this hasher's parameters (login then stores a fresh hash). */
    public function needsRehash(#[\SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options());
    }

    /** Spends the cost of a verification on {@see self::DUMMY_HASH}; the answer is always "no" and is not returned. */
    public function verifyDummy(#[\SensitiveParameter] string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        return [
            'memory_cost' => $this->memoryCost,
            'time_cost' => $this->timeCost,
            'threads' => $this->threads,
        ];
    }
}
