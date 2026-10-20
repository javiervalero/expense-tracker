<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

/**
 * What counting one attempt in a bucket gives back: the decision, and the end of the window the attempt was counted
 * in (the stored `expiresAt`), so that a later refund can be limited to that same window.
 */
final readonly class ThrottleHit
{
    public function __construct(
        public ThrottleDecision $decision,
        public int $windowExpiresAt,
    ) {}
}
