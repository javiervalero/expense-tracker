<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Principal\Clean;

use App\Auth\Session\Principal;

final class NamesThePrincipalClass
{
    public function run(string $class): array
    {
        // Principal::class with no Reflection or newInstance in the file is only a name.
        return [Principal::class, new \stdClass(), new static(), new self()];
    }
}
