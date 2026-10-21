<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\AuthKeys;
use App\Auth\Email;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthKeysTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';

    public function testUserKeyIsTheProfileItemOfTheUser(): void
    {
        self::assertSame(['PK' => 'USER#' . self::ID, 'SK' => 'PROFILE'], AuthKeys::user(self::ID));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => [strtoupper(self::ID)];
        yield 'not a uuid' => ['USER#other'];
        yield 'trailing newline' => [self::ID . "\n"];
        yield 'wrong version' => ['3f2b8c1e-5d4a-1b7e-9a60-1c2d3e4f5a6b'];
    }

    #[DataProvider('badIds')]
    public function testUserKeyRefusesAnIdThatIsNotACanonicalLowerCaseUuid(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthKeys::user($id);
    }

    public function testEmailLockKeyUsesTheNormalisedAddress(): void
    {
        $email = Email::parse('  Ana@Example.COM ');
        self::assertInstanceOf(Email::class, $email);

        self::assertSame(['PK' => 'EMAIL#ana@example.com', 'SK' => 'EMAIL'], AuthKeys::emailLock($email));
    }

    public function testALocalPartWithAHashIsFineBecauseTheKeyIsNeverParsedBack(): void
    {
        $email = Email::parse('a#b@example.com');
        self::assertInstanceOf(Email::class, $email);

        self::assertSame('EMAIL#a#b@example.com', AuthKeys::emailLock($email)['PK']);
    }

    public function testSessionKeyIsTheSessionItemOfAHash(): void
    {
        $hash = str_repeat('ab12', 16);

        self::assertSame(['PK' => 'SESSION#' . $hash, 'SK' => 'SESSION'], AuthKeys::session($hash));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHashes(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'upper case' => [str_repeat('A', 64)];
        yield 'not hex' => [str_repeat('g', 64)];
        yield 'trailing newline' => [str_repeat('a', 64) . "\n"];
    }

    #[DataProvider('badHashes')]
    public function testSessionKeyRefusesAnythingButSixtyFourLowerCaseHexCharacters(string $hash): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthKeys::session($hash);
    }

    public function testThrottleKeyIsScopeAndKeyUnderOneSortKey(): void
    {
        $hash = hash('sha256', 'ana@example.com');

        self::assertSame(['PK' => 'THROTTLE#LOGIN-EMAIL#' . $hash, 'SK' => 'THROTTLE'], AuthKeys::throttle(AuthKeys::LOGIN_EMAIL, $hash));
        self::assertSame(['PK' => 'THROTTLE#LOGIN-IP#192.0.2.7', 'SK' => 'THROTTLE'], AuthKeys::throttle(AuthKeys::LOGIN_IP, '192.0.2.7'));
        self::assertSame(['PK' => 'THROTTLE#REGISTER-IP#2001:db8:1:2::/64', 'SK' => 'THROTTLE'], AuthKeys::throttle(AuthKeys::REGISTER_IP, '2001:db8:1:2::/64'));
        self::assertSame(['PK' => 'THROTTLE#LOGIN-NET#2001:db8:1::/48', 'SK' => 'THROTTLE'], AuthKeys::throttle(AuthKeys::LOGIN_NET, '2001:db8:1::/48'));
        self::assertSame(['PK' => 'THROTTLE#REGISTER-NET#::/48', 'SK' => 'THROTTLE'], AuthKeys::throttle(AuthKeys::REGISTER_NET, '::/48'));
        self::assertSame(['PK' => 'THROTTLE#LOGIN-IP#unknown', 'SK' => 'THROTTLE'], AuthKeys::throttle(AuthKeys::LOGIN_IP, 'unknown'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badThrottleKeys(): iterable
    {
        $hash = hash('sha256', 'ana@example.com');
        yield 'unknown scope' => ['LOGIN-OTHER', '192.0.2.7'];
        yield 'lower-case scope' => ['login-ip', '192.0.2.7'];
        yield 'empty scope' => ['', '192.0.2.7'];
        yield 'email scope with the raw email' => [AuthKeys::LOGIN_EMAIL, 'ana@example.com'];
        yield 'email scope with upper-case hex' => [AuthKeys::LOGIN_EMAIL, strtoupper($hash)];
        yield 'email scope with a short hash' => [AuthKeys::LOGIN_EMAIL, substr($hash, 1)];
        yield 'email scope with a newline' => [AuthKeys::LOGIN_EMAIL, $hash . "\n"];
        yield 'ip scope empty' => [AuthKeys::LOGIN_IP, ''];
        yield 'ip scope with a hostname' => [AuthKeys::LOGIN_IP, 'example.com'];
        yield 'ip scope with a leading space' => [AuthKeys::LOGIN_IP, ' 192.0.2.7'];
        yield 'ip scope with a trailing newline' => [AuthKeys::REGISTER_IP, "192.0.2.7\n"];
        yield 'ip scope with a port' => [AuthKeys::LOGIN_IP, '192.0.2.7:443'];
        yield 'ip scope with an ipv6 address, not a prefix' => [AuthKeys::LOGIN_IP, '2001:db8::1'];
        yield 'ip scope with the wrong prefix length' => [AuthKeys::LOGIN_IP, '2001:db8:1::/48'];
        yield 'ip scope with host bits set' => [AuthKeys::REGISTER_IP, '2001:db8:1:2::1/64'];
        yield 'ip scope with a non-canonical prefix' => [AuthKeys::LOGIN_IP, '2001:0db8:1:2::/64'];
        yield 'ip scope with upper case unknown' => [AuthKeys::LOGIN_IP, 'UNKNOWN'];
        yield 'net scope with unknown' => [AuthKeys::LOGIN_NET, 'unknown'];
        yield 'net scope with ipv4' => [AuthKeys::REGISTER_NET, '192.0.2.7'];
        yield 'net scope with a /64' => [AuthKeys::LOGIN_NET, '2001:db8:1:2::/64'];
        yield 'net scope with host bits set' => [AuthKeys::LOGIN_NET, '2001:db8:1:2::/48'];
        yield 'net scope with a newline' => [AuthKeys::LOGIN_NET, "2001:db8:1::/48\n"];
    }

    #[DataProvider('badThrottleKeys')]
    public function testThrottleKeyRefusesAnUnknownScopeOrAKeyOfTheWrongShape(string $scope, string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthKeys::throttle($scope, $key);
    }
}
