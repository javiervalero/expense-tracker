<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Principal\Clean;

use App\Auth\Session\Principal;

final class MethodNamedNew
{
    public static function new(Principal $principal): self
    {
        return new self();
    }

    public function &new2(): int
    {
        $value = 1;

        return $value;
    }

    public function run(object $x, string $class): array
    {
        return [
            Other::new(1),
            static::new(2),
            Other:: /* a comment */ new (3),
            $x->new(4),
            $x?->new(5),
            Other::new(...),
        ];
    }
}
