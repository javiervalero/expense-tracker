<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\PasswordHasher\Leaky;

use App\Auth\Password\PasswordHasher;
use App\Auth\Password\PasswordHasher as Hasher;

final class Leaky
{
    public function run(): array
    {
        return [
            new PasswordHasher(8, 1, 1),
            new \App\Auth\Password\PasswordHasher(8, 1, 1),
            new  passwordhasher(8, 1, 1),
        ];
    }
}
