<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Clock;

use App\Infrastructure\Clock\SystemClock;
use App\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testNowIsInUtcAndCloseToTheRealTime(): void
    {
        $before = time();

        $now = (new SystemClock())->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertSame(0, $now->getOffset());
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual(time(), $now->getTimestamp());
    }

    public function testNowIsUtcEvenWhenTheDefaultTimezoneIsNot(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testFrozenClockStandsStillUntilAdvanced(): void
    {
        $clock = new FrozenClock('2026-10-16T12:00:00+00:00');

        self::assertEquals($clock->now(), $clock->now());

        $clock->advance(90);
        self::assertSame('2026-10-16T12:01:30+00:00', $clock->now()->format(DATE_ATOM));

        $clock->advance(-30);
        self::assertSame('2026-10-16T12:01:00+00:00', $clock->now()->format(DATE_ATOM));
    }
}
