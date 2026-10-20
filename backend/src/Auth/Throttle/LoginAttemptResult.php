<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

/**
 * The outcome of LoginThrottle::attempt(): the decision and, only when it is allowed, the {@see LoginAttempt} to hand
 * back to LoginThrottle::succeeded() after a successful login.
 */
final readonly class LoginAttemptResult
{
    public function __construct(
        public ThrottleDecision $decision,
        public ?LoginAttempt $attempt,
    ) {}
}
