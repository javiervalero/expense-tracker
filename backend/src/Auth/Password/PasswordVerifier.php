<?php

declare(strict_types=1);

namespace App\Auth\Password;

/**
 * What the register and login handlers need from password hashing. {@see PasswordHasher} is the only implementation
 * in src/ (an architecture test keeps production code from building one with other parameters); the interface exists
 * so tests can observe which operation a handler runs, for example that an unknown e-mail runs the dummy verification
 * and never a real one (A16).
 */
interface PasswordVerifier
{
    /**
     * @throws \ValueError when PHP cannot hash (for example it cannot allocate the Argon2id memory)
     */
    public function hash(#[\SensitiveParameter] string $password): string;

    public function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool;

    /** True when the hash is not Argon2id with the current parameters (login then stores a fresh hash). */
    public function needsRehash(#[\SensitiveParameter] string $hash): bool;

    /** Spends the cost of a verification on a constant dummy hash; the answer is not returned. */
    public function verifyDummy(#[\SensitiveParameter] string $password): void;
}
