<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\NativeSessions\Leaky;

use function setcookie as renamedCookie;

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

    public function moreRules(): void
    {
        session_regenerate_id();
        $a = [1, 2];
        array_rand($a);
        shuffle($a);
        str_shuffle('abc');
        srand(1);
        mt_srand(1);
        getallheaders();
        filter_input(INPUT_COOKIE, 'a');
        new \Random\Engine\Mt19937(1);
        $x = namespace\rand();
        $s = $_SESSION['a'] ?? $_REQUEST['a'] ?? $GLOBALS['_COOKIE'];
        $callable = 'setcookie';
        $other = '\Session_Id';
        header('Set-Cookie: a=b');
        $first = setcookie(...);
    }
}
