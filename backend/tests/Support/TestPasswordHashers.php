<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Password\PasswordHasher;

/**
 * The cheap Argon2id parameters of the tests, in one place (production code can only build the production hasher).
 * Hashes made by `cheap()` are current for `cheap()` and outdated for `upgrading()`.
 */
final class TestPasswordHashers
{
    /** The parameters of an ordinary test hash: fast, and what `cheap()` considers current. */
    public static function cheap(): PasswordHasher
    {
        return new PasswordHasher(memoryCost: 8, timeCost: 1, threads: 1);
    }

    /** A hasher whose parameters are stricter than `cheap()`'s, so `cheap()` hashes need a rehash with it. */
    public static function upgrading(): PasswordHasher
    {
        return new PasswordHasher(memoryCost: 16, timeCost: 1, threads: 1);
    }
}
