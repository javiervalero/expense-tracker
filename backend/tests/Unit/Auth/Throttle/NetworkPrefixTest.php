<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Throttle;

use App\Auth\Throttle\NetworkPrefix;
use App\Auth\AuthKeys;
use App\Http\ClientAddress;
use App\Http\ClientIp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NetworkPrefixTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ipv6Addresses(): iterable
    {
        yield 'the /48 of an address' => ['2001:db8:1:2::1', '2001:db8:1::/48'];
        yield 'loopback' => ['::1', '::/48'];
        yield 'two /64s of one /48 share it' => ['2001:db8:1:ffff::9', '2001:db8:1::/48'];
        yield 'bits after the 48th are dropped' => ['2001:db8:1:2:3:4:5:6', '2001:db8:1::/48'];
        yield 'full length address' => ['2001:db8:a:b:c:d:e:f', '2001:db8:a::/48'];
    }

    #[DataProvider('ipv6Addresses')]
    public function testIpv6AddressGivesItsSlash48(string $ip, string $expected): void
    {
        self::assertSame($expected, NetworkPrefix::of(ClientAddress::of($ip, 'ignored')));
    }

    public function testIpv4AddressHasNoCoarseTier(): void
    {
        self::assertNull(NetworkPrefix::of(ClientAddress::of('192.0.2.7', '192.0.2.7')));
    }

    public function testUnknownAddressHasNoCoarseTier(): void
    {
        self::assertNull(NetworkPrefix::of(ClientAddress::unknown('missing')));
    }

    public function testAnIpv4MappedAddressParsedByClientIpHasNoCoarseTier(): void
    {
        self::assertNull(NetworkPrefix::of(ClientIp::parse('::ffff:192.0.2.7:443')));
    }

    public function testAnIpv6AddressParsedByClientIpGivesItsSlash48(): void
    {
        self::assertSame('2001:db8:1::/48', NetworkPrefix::of(ClientIp::parse('[2001:db8:1:2::1]:443')));
    }

    public function testACanonicalIpThatIsNotAnAddressGivesNull(): void
    {
        self::assertNull(NetworkPrefix::of(ClientAddress::of('not:an:address', 'x')));
    }

    public function testThePrefixIsWrittenWithTheLengthAuthKeysDefines(): void
    {
        $prefix = NetworkPrefix::of(ClientAddress::of('2001:db8:1:2::1', '2001:db8:1:2::/64'));

        self::assertNotNull($prefix);
        self::assertStringEndsWith('/' . AuthKeys::IPV6_NETWORK_PREFIX_BITS, $prefix);
    }

    public function testThePrefixLengthIsWholeBytes(): void
    {
        $bits = (new \ReflectionClassConstant(AuthKeys::class, 'IPV6_NETWORK_PREFIX_BITS'))->getValue();

        self::assertIsInt($bits);
        self::assertSame(0, $bits % 8);
    }
}
