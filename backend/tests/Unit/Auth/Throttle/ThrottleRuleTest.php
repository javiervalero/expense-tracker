<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Throttle;

use App\Auth\AuthKeys;
use App\Auth\Throttle\ThrottleRule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ThrottleRuleTest extends TestCase
{
    public function testTheRulesAreTheLimitsOfA13(): void
    {
        $this->assertRule(ThrottleRule::loginAccount(), AuthKeys::LOGIN_EMAIL, 10, 900);
        $this->assertRule(ThrottleRule::loginAddress(), AuthKeys::LOGIN_IP, 30, 900);
        $this->assertRule(ThrottleRule::loginNetwork(), AuthKeys::LOGIN_NET, 100, 900);
        $this->assertRule(ThrottleRule::registerAddress(), AuthKeys::REGISTER_IP, 5, 3600);
        $this->assertRule(ThrottleRule::registerNetwork(), AuthKeys::REGISTER_NET, 20, 3600);
    }

    public function testARuleRefusesAnUnknownScope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ThrottleRule('OTHER', 5, 60);
    }

    public function testARuleRefusesALimitBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ThrottleRule(AuthKeys::LOGIN_IP, 0, 60);
    }

    public function testARuleRefusesAWindowBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ThrottleRule(AuthKeys::LOGIN_IP, 5, 0);
    }

    private function assertRule(ThrottleRule $rule, string $scope, int $limit, int $window): void
    {
        self::assertSame($scope, $rule->scope);
        self::assertSame($limit, $rule->limit);
        self::assertSame($window, $rule->windowSeconds);
    }
}
