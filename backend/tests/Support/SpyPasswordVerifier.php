<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Password\PasswordVerifier;
use Throwable;

/**
 * A password verifier that records which operations a handler calls (never the arguments, so a test dump holds no
 * password) and delegates to the verifier it wraps (a cheap one: see TestPasswordHashers), or throws what the test scripted for `hash()`. The cost of
 * the production hash is not what is under test here, only which operation runs.
 */
final class SpyPasswordVerifier implements PasswordVerifier
{
    /** @var list<string> 'hash', 'verify', 'verifyDummy' or 'needsRehash', in call order */
    public array $calls = [];

    public ?Throwable $hashFailure = null;

    public function __construct(private readonly PasswordVerifier $inner) {}

    public function count(string $operation): int
    {
        return count(array_filter($this->calls, static fn(string $call): bool => $call === $operation));
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        $this->calls[] = 'hash';
        if ($this->hashFailure !== null) {
            throw $this->hashFailure;
        }

        return $this->inner->hash($password);
    }

    public function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        $this->calls[] = 'verify';

        return $this->inner->verify($password, $hash);
    }

    public function needsRehash(#[\SensitiveParameter] string $hash): bool
    {
        $this->calls[] = 'needsRehash';

        return $this->inner->needsRehash($hash);
    }

    public function verifyDummy(#[\SensitiveParameter] string $password): void
    {
        // Recorded only: the point of the spy is that the call happened, and the real one costs 64 MiB and ~0.15 s.
        $this->calls[] = 'verifyDummy';
    }
}
