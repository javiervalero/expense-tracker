<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Throttle;

use App\Auth\Throttle\ThrottleDecision;
use PHPUnit\Framework\TestCase;

final class ThrottleDecisionTest extends TestCase
{
    public function testAllowedHasNoRetryAfter(): void
    {
        $decision = ThrottleDecision::allowed();

        self::assertTrue($decision->isAllowed);
        self::assertSame(0, $decision->retryAfterSeconds);
    }

    public function testBlockedCarriesTheRetryAfter(): void
    {
        $decision = ThrottleDecision::blocked(42);

        self::assertFalse($decision->isAllowed);
        self::assertSame(42, $decision->retryAfterSeconds);
    }

    public function testBlockedNeverAdvertisesLessThanOneSecond(): void
    {
        self::assertSame(1, ThrottleDecision::blocked(0)->retryAfterSeconds);
        self::assertSame(1, ThrottleDecision::blocked(-30)->retryAfterSeconds);
    }

    public function testStrictestOfAllAllowedIsAllowed(): void
    {
        self::assertTrue(ThrottleDecision::strictest(ThrottleDecision::allowed(), ThrottleDecision::allowed())->isAllowed);
        self::assertTrue(ThrottleDecision::strictest()->isAllowed);
    }

    public function testStrictestIsTheBlockedOneWithTheLargestRetryAfter(): void
    {
        $decision = ThrottleDecision::strictest(
            ThrottleDecision::blocked(30),
            ThrottleDecision::allowed(),
            ThrottleDecision::blocked(600),
            ThrottleDecision::blocked(5),
        );

        self::assertFalse($decision->isAllowed);
        self::assertSame(600, $decision->retryAfterSeconds);
    }
}
