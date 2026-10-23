<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Principal\Leaky;

use App\Auth\Session\Principal;

final class DynamicNextToStaticNew
{
    public function run(string $class): array
    {
        return [
            Other::new(1),
            new ($class)('u', 'h'),
            Other::new(new ($class)('u', 'h')),
            new $class('u', 'h'),
        ];
    }
}
