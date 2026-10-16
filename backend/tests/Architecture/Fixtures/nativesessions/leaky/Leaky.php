<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\NativeSessions\Leaky;

final class Leaky
{
    public function run(): void
    {
        session_start();
        setcookie('a', 'b');
        \setrawcookie('a', 'b');
        $value = $_COOKIE['a'] ?? null;
        $n = rand() + MT_RAND() + mt_rand(1, 2);
        $id = uniqid();
        $f = \lcg_value();
    }
}
