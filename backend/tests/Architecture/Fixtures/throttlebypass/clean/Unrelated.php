<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ThrottleBypass\Clean;

final class Unrelated
{
    public function run(object $sessions): void
    {
        $sessions->delete('hash');
    }
}
