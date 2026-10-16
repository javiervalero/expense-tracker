<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\NativeSessions\Clean;

/**
 * Prose may mention session_start(), setcookie() and rand() without leaking anything.
 */
final class Clean
{
    // Plain comments may mention uniqid() and $_COOKIE too.
    public function run(object $other): string
    {
        $text = 'session_start and mt_rand in a string are fine';

        return $text . bin2hex(random_bytes(4)) . $other->rand() . $other::uniqid() . self::setcookie() . $this->lcg_value();
    }

    public static function setcookie(): string
    {
        return '';
    }

    public function lcg_value(): string
    {
        return '';
    }
}
