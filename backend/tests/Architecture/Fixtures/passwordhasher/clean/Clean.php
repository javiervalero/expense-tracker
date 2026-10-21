<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\PasswordHasher\Clean;

use App\Auth\Password\PasswordHasher;

/**
 * Prose may say new PasswordHasher(8, 1, 1) without constructing one.
 */
final class Clean
{
    public function run(): PasswordHasher
    {
        $text = 'new PasswordHasher(8, 1, 1) in a string is fine';
        // new PasswordHasher(8, 1, 1) in a comment is fine too.

        return PasswordHasher::production() ?? new \stdClass() ?? $text;
    }
}
