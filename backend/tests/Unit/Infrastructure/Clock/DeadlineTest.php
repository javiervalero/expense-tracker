<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Clock;

use App\Infrastructure\Clock\Deadline;
use App\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class DeadlineTest extends TestCase
{
    public function testItHasNotPassedBeforeItsInstant(): void
    {
        $clock = new FrozenClock('2026-10-16T12:00:00+00:00');

        $deadline = Deadline::after($clock, 8);

        self::assertFalse($deadline->hasPassed());
        $clock->advance(7);
        self::assertFalse($deadline->hasPassed());
    }

    public function testItHasPassedAtItsInstantAndAfter(): void
    {
        $clock = new FrozenClock('2026-10-16T12:00:00+00:00');
        $deadline = Deadline::after($clock, 8);

        $clock->advance(8);
        self::assertTrue($deadline->hasPassed());
        $clock->advance(1);
        self::assertTrue($deadline->hasPassed());
    }

    public function testAZeroBudgetHasPassedAtOnce(): void
    {
        self::assertTrue(Deadline::after(new FrozenClock(), 0)->hasPassed());
    }
}
