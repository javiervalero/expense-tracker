<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Throttle;

use App\Auth\AuthKeys;
use App\Auth\Throttle\RegisterThrottle;
use App\Http\ClientAddress;
use App\Tests\Support\InMemoryThrottleStore;
use PHPUnit\Framework\TestCase;

final class RegisterThrottleTest extends TestCase
{
    private const IPV6_PREFIX_64 = '2001:db8:1:2::/64';
    private const IPV6_PREFIX_48 = '2001:db8:1::/48';

    private InMemoryThrottleStore $store;
    private RegisterThrottle $throttle;

    protected function setUp(): void
    {
        $this->store = new InMemoryThrottleStore();
        $this->throttle = new RegisterThrottle($this->store);
    }

    public function testAnIpv4AttemptCountsTheAddressBucketOnly(): void
    {
        $decision = $this->throttle->attempt(ClientAddress::of('192.0.2.7', '192.0.2.7'));

        self::assertTrue($decision->isAllowed);
        self::assertSame(['hit|REGISTER-IP|192.0.2.7'], $this->store->calls);
    }

    public function testAnIpv6AttemptCountsTheSlash64AndSlash48Buckets(): void
    {
        $this->throttle->attempt(ClientAddress::of('2001:db8:1:2::1', self::IPV6_PREFIX_64));

        self::assertSame(
            ['hit|REGISTER-IP|' . self::IPV6_PREFIX_64, 'hit|REGISTER-NET|' . self::IPV6_PREFIX_48],
            $this->store->calls,
        );
    }

    public function testTheSixthAttemptFromOneAddressIsBlocked(): void
    {
        $address = ClientAddress::of('192.0.2.7', '192.0.2.7');
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($this->throttle->attempt($address)->isAllowed);
        }

        self::assertFalse($this->throttle->attempt($address)->isAllowed);
    }

    public function testTheNetworkBucketBlocksAfterTwentyEvenFromDifferentSlash64s(): void
    {
        $this->store->seed(AuthKeys::REGISTER_NET, self::IPV6_PREFIX_48, 20);

        $decision = $this->throttle->attempt(ClientAddress::of('2001:db8:1:9::5', '2001:db8:1:9::/64'));

        self::assertFalse($decision->isAllowed);
    }

    public function testRetryAfterIsTheLargestRemainingTime(): void
    {
        $this->store->seed(AuthKeys::REGISTER_IP, self::IPV6_PREFIX_64, 5);
        $this->store->seed(AuthKeys::REGISTER_NET, self::IPV6_PREFIX_48, 20);
        $this->store->retryAfterByScope = [AuthKeys::REGISTER_IP => 3000, AuthKeys::REGISTER_NET => 1200];

        $decision = $this->throttle->attempt(ClientAddress::of('2001:db8:1:2::1', self::IPV6_PREFIX_64));

        self::assertFalse($decision->isAllowed);
        self::assertSame(3000, $decision->retryAfterSeconds);
    }

    public function testTheUnknownAddressIsOneSharedBucket(): void
    {
        $this->throttle->attempt(ClientAddress::unknown('missing'));
        $this->throttle->attempt(ClientAddress::unknown('invalid'));

        self::assertSame(2, $this->store->count(AuthKeys::REGISTER_IP, 'unknown'));
        self::assertSame(['hit|REGISTER-IP|unknown', 'hit|REGISTER-IP|unknown'], $this->store->calls);
    }
}
