<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ThrottleBypass\Clean;

use App\Auth\Throttle\ThrottleStore;

interface Clean extends ThrottleStore
{
    public function refund(string $rule, string $key, int $until): void;

    public function delete(string $rule, string $key): void;
}
