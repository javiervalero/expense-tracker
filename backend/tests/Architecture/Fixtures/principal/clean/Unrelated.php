<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Principal\Clean;

final class Unrelated
{
    public function run(string $class): object
    {
        $reflection = new \ReflectionClass(\stdClass::class);

        return $reflection->newInstance() ?? new $class() ?? new ($class)();
    }
}
