<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\Clock\Clock;

/**
 * A clock that stands still until a test moves it, so time-dependent rules can be tested without sleeping.
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $at = '2026-10-16T12:00:00+00:00')
    {
        $this->now = (new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
