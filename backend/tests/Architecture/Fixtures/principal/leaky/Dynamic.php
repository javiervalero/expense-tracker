<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Principal\Leaky;

use App\Auth\Session\Principal;

final class Dynamic
{
    public function run(string $class): array
    {
        $reflection = new \ReflectionClass(Principal::class);

        return [
            new $class('u', 'h'),
            new ($class)('u', 'h'),
            $reflection->newInstance('u', 'h'),
            $reflection->newInstanceArgs(['u', 'h']),
            $reflection->newInstanceWithoutConstructor(),
            (new \ReflectionClass(\App\Auth\Session\Principal::class))->getName(),
        ];
    }
}
