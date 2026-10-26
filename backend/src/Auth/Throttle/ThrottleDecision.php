<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

/**
 * Allowed, or blocked with the whole seconds until the end of the window (never less than 1, never more than the
 * window: the counter caps it). It never says which
 * bucket decided: the response is one generic body (A13).
 */
final readonly class ThrottleDecision
{
    private function __construct(
        public bool $isAllowed,
        public int $retryAfterSeconds,
    ) {}

    public static function allowed(): self
    {
        return new self(true, 0);
    }

    public static function blocked(int $retryAfterSeconds): self
    {
        return new self(false, max(1, $retryAfterSeconds));
    }

    /**
     * Blocked if any decision is blocked, with the largest Retry-After among the blocked ones.
     */
    public static function strictest(self ...$decisions): self
    {
        $retryAfter = 0;
        foreach ($decisions as $decision) {
            if (!$decision->isAllowed) {
                $retryAfter = max($retryAfter, $decision->retryAfterSeconds);
            }
        }

        return $retryAfter > 0 ? self::blocked($retryAfter) : self::allowed();
    }
}
