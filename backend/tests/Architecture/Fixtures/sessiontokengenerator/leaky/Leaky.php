<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\SessionTokenGenerator\Leaky;

use App\Auth\Session\SessionRepository;
use App\Auth\Session\SessionRepository as Repository;

final class Leaky
{
    public function run(object $items, object $clock, \Closure $generator): array
    {
        return [
            new SessionRepository($items, $clock, $generator),
            new \App\Auth\Session\SessionRepository($items, $clock, static fn() => 1),
            new  sessionrepository($items, $clock, $generator,),
            new SessionRepository(items: $items, clock: $clock, generateToken: $generator),
            new SessionRepository(clock: $clock, items: $items, generateToken: null),
        ];
    }
}
