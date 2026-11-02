<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\Clock\Clock;

/**
 * A clock that says "the start" for the first `$freshReads` calls to now(), and one hour later from then on. A test
 * that builds a Deadline of a few seconds with it can let a number of deadline checks pass, with no sleeping. Building
 * the Deadline is the first read, so `new ExpiringClock(1)` gives a deadline that has passed at its first check, and
 * `new ExpiringClock(2)` lets exactly one check pass.
 */
final class ExpiringClock implements Clock
{
    public const string START = '2026-10-16T12:00:00+00:00';

    private int $reads = 0;

    public function __construct(private readonly int $freshReads) {}

    public function now(): \DateTimeImmutable
    {
        $start = new \DateTimeImmutable(self::START);

        return $this->reads++ < $this->freshReads ? $start : $start->modify('+1 hour');
    }
}
