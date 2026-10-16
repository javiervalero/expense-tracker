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
        $prose = 'We never call setcookie directly; the Set-Cookie header is built by Response. $_SESSION too.';
        $name = 'shuffle the cards, then rand';

        return $text . $prose . $name . bin2hex(random_bytes(4)) . $other->rand() . $other::uniqid() . self::setcookie() . $this->lcg_value()
            . $other?->shuffle() . $other::session_start() . $this->getallheaders();
    }

    public static function setcookie(): string
    {
        return '';
    }

    public function lcg_value(): string
    {
        return '';
    }

    public function shuffle(): string
    {
        return '';
    }

    public function getallheaders(): string
    {
        return '';
    }
}
