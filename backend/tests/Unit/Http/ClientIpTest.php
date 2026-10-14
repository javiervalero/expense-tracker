<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\ClientAddress;
use App\Http\ClientIp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}> value, ip, throttle key
     */
    public static function accepted(): iterable
    {
        yield '3 plain IPv4' => ['203.0.113.7:4711', '203.0.113.7', '203.0.113.7'];
        yield '4 lowest port' => ['203.0.113.7:1', '203.0.113.7', '203.0.113.7'];
        yield '4 highest port' => ['203.0.113.7:65535', '203.0.113.7', '203.0.113.7'];
        yield '5 bracketed loopback' => ['[::1]:8080', '::1', '::/64'];
        yield '6 bracketed full IPv6' => ['[2001:db8:85a3:8d3:1319:8a2e:370:7348]:443', '2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3:8d3::/64'];
        yield '7 unbracketed full IPv6 (CloudFront form)' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348:443', '2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3:8d3::/64'];
        yield '8 upper case is canonicalised' => ['2001:DB8::1:443', '2001:db8::1', '2001:db8::/64'];
        yield '9 mapped, unbracketed' => ['::ffff:198.51.100.9:5000', '198.51.100.9', '198.51.100.9'];
        yield '9 mapped, bracketed' => ['[::ffff:198.51.100.9]:5000', '198.51.100.9', '198.51.100.9'];
        yield '9 mapped, hex tail' => ['[::ffff:c633:6409]:5000', '198.51.100.9', '198.51.100.9'];
        yield '22 ambiguous without port' => ['2001:db8::1:2', '2001:db8::1', '2001:db8::/64'];
        yield 'private IPv4 is legitimate locally' => ['172.18.0.5:41234', '172.18.0.5', '172.18.0.5'];
        yield 'long form is canonicalised' => ['[2001:0db8:0000:0000:0000:0000:0000:0001]:80', '2001:db8::1', '2001:db8::/64'];
        yield 'unspecified address' => ['[::]:80', '::', '::/64'];
    }

    #[DataProvider('accepted')]
    public function testAValidValueGivesTheCanonicalAddressAndKey(string $value, string $ip, string $key): void
    {
        $address = ClientIp::parse($value);

        self::assertSame($ip, $address->ip);
        self::assertSame($key, $address->throttleKey);
        self::assertNull($address->reason);
    }

    /**
     * @return iterable<string, array{string, string}> value, reason
     */
    public static function rejected(): iterable
    {
        yield '2 empty' => ['', 'empty'];
        yield '10 nginx merge' => ['203.0.113.7:1, 198.51.100.2:2', 'multiple-values'];
        yield '11 comma, no space' => ['203.0.113.7:1,198.51.100.2:2', 'multiple-values'];
        yield '11 bracketed pair' => ['[::1]:1, [::2]:2', 'multiple-values'];
        yield '11 trailing comma' => ['203.0.113.7:1,', 'multiple-values'];
        yield '12 IPv4 without port' => ['203.0.113.7', 'invalid'];
        yield '12 bracketed without port' => ['[::1]', 'invalid'];
        yield '12 ::1 without port' => ['::1', 'invalid'];
        yield '13 port 0' => ['203.0.113.7:0', 'invalid'];
        yield '13 port 65536' => ['203.0.113.7:65536', 'invalid'];
        yield '13 port 99999' => ['203.0.113.7:99999', 'invalid'];
        yield '13 port 123456' => ['203.0.113.7:123456', 'invalid'];
        yield '14 empty port' => ['203.0.113.7:', 'invalid'];
        yield '14 negative port' => ['203.0.113.7:-1', 'invalid'];
        yield '14 plus port' => ['203.0.113.7:+80', 'invalid'];
        yield '14 hex port' => ['203.0.113.7:8a', 'invalid'];
        yield '14 leading zero port' => ['203.0.113.7:080', 'invalid'];
        yield '14 bracketed leading zero port' => ['[::1]:080', 'invalid'];
        yield '15 garbage' => ['garbage', 'invalid'];
        yield '15 not an ip' => ['not-an-ip:80', 'invalid'];
        yield '15 colons' => [':::', 'invalid'];
        yield '15 port only' => [':80', 'invalid'];
        yield '15 empty brackets' => ['[]:80', 'invalid'];
        yield '15 only a colon' => [':', 'invalid'];
        yield '15 only brackets' => ['[]', 'invalid'];
        yield '16 octet over 255' => ['256.1.1.1:80', 'invalid'];
        yield '16 leading zero octet' => ['01.2.3.4:80', 'invalid'];
        yield '16 three octets' => ['1.2.3:80', 'invalid'];
        yield '17 leading space' => [' 203.0.113.7:80', 'invalid'];
        yield '17 trailing space' => ['203.0.113.7:80 ', 'invalid'];
        yield '17 tab' => ["203.0.113.7:80\t", 'invalid'];
        yield '17 CRLF' => ["203.0.113.7:80\r\n", 'invalid'];
        yield '17 NUL' => ["203.0.113.7:80\0", 'invalid'];
        yield '18 zone id' => ['fe80::1%eth0:80', 'invalid'];
        yield '18 encoded zone id' => ['[fe80::1%25eth0]:80', 'invalid'];
        yield '19 bracketed IPv4' => ['[203.0.113.7]:80', 'invalid'];
        yield '19 no colon after bracket' => ['[::1]80', 'invalid'];
        yield '19 unclosed bracket' => ['[::1:80', 'invalid'];
        yield '19 unopened bracket' => ['::1]:80', 'invalid'];
        yield '19 double brackets' => ['[[::1]]:80', 'invalid'];
        yield '19 text after the port' => ['[::1]:80x', 'invalid'];
        yield '19 text after the bracket' => ['[::1]x:80', 'invalid'];
        yield '20 65 characters' => ['[2001:db8:85a3:8d3:1319:8a2e:370:7348]:443' . str_repeat('0', 23), 'too-long'];
        yield '20 long garbage' => [str_repeat('a', 65), 'too-long'];
        yield '21 not UTF-8' => ["\xff\xfe:80", 'invalid'];
        yield 'IPv6 with too many groups' => ['[1:2:3:4:5:6:7:8:9]:80', 'invalid'];
        yield 'IPv4 form with letters' => ['abc.def.0.1:80', 'invalid'];
        yield 'bracketed mapped with bad tail' => ['[::ffff:198.51.100.999]:80', 'invalid'];
        yield 'one-sided letters beyond hex' => ['[::g]:80', 'invalid'];
    }

    #[DataProvider('rejected')]
    public function testAnInvalidValueFailsClosedWithAReason(string $value, string $reason): void
    {
        $address = ClientIp::parse($value);

        self::assertNull($address->ip);
        self::assertSame('unknown', $address->throttleKey);
        self::assertSame($reason, $address->reason);
    }

    public function testAnAbsentHeaderIsMissing(): void
    {
        $address = ClientIp::parse(null);

        self::assertNull($address->ip);
        self::assertSame('unknown', $address->throttleKey);
        self::assertSame('missing', $address->reason);
    }

    public function testASixtyFourCharacterValueIsNotTooLong(): void
    {
        $value = str_pad('1.1.1.1:1', 64, ' ');

        self::assertSame(64, strlen($value));
        self::assertSame('invalid', ClientIp::parse($value)->reason);
    }

    public function testTwoAddressesInTheSame64ShareTheKeyAndDifferentOnesDoNot(): void
    {
        $a = ClientIp::parse('[2001:db8:1:2::1]:1');
        $b = ClientIp::parse('[2001:db8:1:2:ffff::9]:2');
        $c = ClientIp::parse('[2001:db8:1:3::1]:1');

        self::assertSame($a->throttleKey, $b->throttleKey);
        self::assertNotSame($a->throttleKey, $c->throttleKey);
        self::assertSame('2001:db8:1:2::/64', $a->throttleKey);
    }

    public function testTheUnknownAddressIsFailClosed(): void
    {
        $address = ClientAddress::unknown('missing');

        self::assertNull($address->ip);
        self::assertSame('unknown', $address->throttleKey);
        self::assertSame('missing', $address->reason);
    }
}
