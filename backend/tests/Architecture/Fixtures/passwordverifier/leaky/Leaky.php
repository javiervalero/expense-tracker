<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\PasswordVerifier\Leaky;

use App\Auth\Password\PasswordVerifier;
use App\Auth\Password\PasswordVerifier as Verifier;

final class Named implements PasswordVerifier {}

final class Qualified implements \Countable, \App\Auth\Password\PasswordVerifier {}

final class Lowercase implements passwordverifier {}

final class Anonymous
{
    public function run(): object
    {
        return new class implements PasswordVerifier {};
    }
}
