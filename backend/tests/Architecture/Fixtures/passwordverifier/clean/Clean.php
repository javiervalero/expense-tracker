<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\PasswordVerifier\Clean;

use App\Auth\Password\PasswordVerifier;
use Countable;

/**
 * Prose may say implements PasswordVerifier without implementing it.
 */
final class Clean implements Countable
{
    public function __construct(private readonly PasswordVerifier $verifier) {}

    public function count(): int
    {
        $text = 'implements PasswordVerifier in a string is fine';
        // implements PasswordVerifier in a comment is fine too.

        return $this->verifier instanceof PasswordVerifier ? strlen($text) : 0;
    }
}
