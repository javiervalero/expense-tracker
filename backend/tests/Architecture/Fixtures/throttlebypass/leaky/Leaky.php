<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ThrottleBypass\Leaky;

use App\Auth\Throttle\CachedThrottleStore;
use App\Auth\Throttle\LoginAttempt;
use App\Auth\Throttle\LoginAttempt as LA;
use App\Auth\Throttle\ThrottleStore;

final class Leaky
{
    public function run(ThrottleStore $store, object $attempt, object $throttle, ?object $maybe): void
    {
        LoginAttempt::issuedBy($throttle, []);
        LA::issuedBy($throttle, []);
        self::issuedBy($throttle, []);
        \App\Auth\Throttle\LoginAttempt::issuedBy($throttle, []);
        $attempt->consume($throttle);
        $attempt->Consume($throttle);
        $maybe?->refund('rule', 'key', 1);
        $attempt
            ->consume(
                $throttle,
            );
        $store->refund('rule', 'key', 1);
        $store->delete('rule', 'key');
        call_user_func([$store, 'refund'], 'rule', 'key', 1);
    }

    public function decorated(CachedThrottleStore $cache): void
    {
        $cache->delete('rule', 'key');
    }
}
