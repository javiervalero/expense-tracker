<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Principal\Leaky;

use App\Auth\Session\Principal;
use App\Auth\Session\Principal as Caller;

final class Leaky
{
    public function run(): array
    {
        return [
            new Principal('u', 'h'),
            new \App\Auth\Session\Principal('u', 'h'),
            new  principal('u', 'h'),
        ];
    }
}
