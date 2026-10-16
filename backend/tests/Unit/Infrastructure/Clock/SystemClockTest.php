<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Clock;

use App\Infrastructure\Clock\SystemClock;
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

    public function testTwoCallsInARowAreMonotonic(): void
    {
        $clock = new SystemClock();

        $first = $clock->now();
        $second = $clock->now();

        self::assertLessThanOrEqual($second, $first);
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
}
