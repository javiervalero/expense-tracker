<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Principal\Clean;

use App\Auth\Session\Principal;

final class AmpersandMethodNamedNew
{
    public function &new(Principal $principal): int
    {
        $value = 1;

        return $value;
    }
}
