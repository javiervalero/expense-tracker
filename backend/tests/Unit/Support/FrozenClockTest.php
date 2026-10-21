<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class FrozenClockTest extends TestCase
{
    public function testStandsStillUntilAdvanced(): void
    {
        $clock = new FrozenClock('2026-10-16T12:00:00+00:00');

        self::assertEquals($clock->now(), $clock->now());

        $clock->advance(90);
        self::assertSame('2026-10-16T12:01:30+00:00', $clock->now()->format(DATE_ATOM));

        $clock->advance(-30);
        self::assertSame('2026-10-16T12:01:00+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testNormalisesANonUtcInputToUtc(): void
    {
        $clock = new FrozenClock('2026-10-16T14:30:00+02:00');

        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
        self::assertSame('2026-10-16T12:30:00+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testAdvanceDoesNotChangeAPreviouslyReturnedInstant(): void
    {
        $clock = new FrozenClock('2026-10-16T12:00:00+00:00');
        $before = $clock->now();

        $clock->advance(60);

        self::assertSame('2026-10-16T12:00:00+00:00', $before->format(DATE_ATOM));
        self::assertSame('2026-10-16T12:01:00+00:00', $clock->now()->format(DATE_ATOM));
    }
}
