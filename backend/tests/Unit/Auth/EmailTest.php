<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\Email;
use App\Auth\InvalidEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testItTrimsAndLowerCases(): void
    {
        $email = Email::parse("  Alice@Example.COM \t\n");

        self::assertInstanceOf(Email::class, $email);
        self::assertSame('alice@example.com', $email->value());
    }

    public function testPlusTagsAndDotsAreKept(): void
    {
        $email = Email::parse('A.Li+Tag@Example.com');

        self::assertInstanceOf(Email::class, $email);
        self::assertSame('a.li+tag@example.com', $email->value());
    }

    public function testExactly254BytesPass(): void
    {
        $address = $this->addressOfLength(254);

        self::assertSame(254, strlen($address));
        $email = Email::parse($address);
        self::assertInstanceOf(Email::class, $email);
        self::assertSame($address, $email->value());
    }

    public function testThe255thByteFails(): void
    {
        self::assertSame(InvalidEmail::TooLong, Email::parse($this->addressOfLength(255)));
    }

    public function testTheLengthIsCountedAfterTrimming(): void
    {
        $email = Email::parse('  ' . $this->addressOfLength(254) . '  ');

        self::assertInstanceOf(Email::class, $email);
    }

    public function testEmptyAndBlankAreRefused(): void
    {
        self::assertSame(InvalidEmail::Empty, Email::parse(''));
        self::assertSame(InvalidEmail::Empty, Email::parse("  \t\r\n "));
    }

    public function testNoAtSignIsRefused(): void
    {
        self::assertSame(InvalidEmail::Malformed, Email::parse('alice.example.com'));
    }

    public function testTwoAtSignsAreRefused(): void
    {
        self::assertSame(InvalidEmail::Malformed, Email::parse('alice@bob@example.com'));
    }

    public function testAUnicodeLocalPartIsRefused(): void
    {
        self::assertSame(InvalidEmail::NonAscii, Email::parse("j\u{f6}rg@example.com"));
    }

    public function testAUnicodeDomainIsRefused(): void
    {
        self::assertSame(InvalidEmail::NonAscii, Email::parse("alice@b\u{fc}cher.example"));
    }

    #[DataProvider('controlCharacters')]
    public function testAControlCharacterInsideIsRefused(string $character): void
    {
        self::assertSame(InvalidEmail::ControlCharacter, Email::parse("ali{$character}ce@example.com"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function controlCharacters(): iterable
    {
        yield 'NUL' => ["\0"];
        yield 'SOH' => ["\x01"];
        yield 'tab' => ["\t"];
        yield 'newline' => ["\n"];
        yield 'carriage return' => ["\r"];
        yield 'unit separator' => ["\x1F"];
        yield 'DEL' => ["\x7F"];
    }

    public function testAHeaderInjectionAttemptIsRefused(): void
    {
        self::assertSame(InvalidEmail::ControlCharacter, Email::parse("a@example.com\r\nBcc: x@example.com"));
    }

    public function testASurroundingNulIsNotTrimmedAway(): void
    {
        self::assertSame(InvalidEmail::ControlCharacter, Email::parse("alice@example.com\0"));
    }

    public function testInvalidUtf8IsRefused(): void
    {
        self::assertSame(InvalidEmail::NonAscii, Email::parse("al\xC3ice@example.com"));
    }

    #[DataProvider('malformed')]
    public function testOtherMalformedAddressesAreRefused(string $address): void
    {
        self::assertSame(InvalidEmail::Malformed, Email::parse($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'no local part' => ['@example.com'];
        yield 'no domain' => ['alice@'];
        yield 'space inside' => ['ali ce@example.com'];
        yield 'comma' => ['alice,bob@example.com'];
        yield 'domain with dot at the end' => ['alice@example.'];
        yield 'local part over 64' => [str_repeat('a', 65) . '@example.com'];
        yield 'no dot in a local domain' => ['a@localhost'];
        yield 'no dot in the domain' => ['alice@example'];
        yield 'quoted local part' => ['"alice"@example.com'];
        yield 'quoted local part with a backslash' => ['"\\alice"@example.com'];
        yield 'quoted local part with a space' => ['"alice smith"@example.com'];
        yield 'quoted address in front of another domain' => ['"victim@example.com"@attacker.example'];
        yield 'IPv4 domain literal' => ['alice@[192.0.2.1]'];
        yield 'IPv6 domain literal' => ['alice@[ipv6:2001:db8::1]'];
    }

    public function testTwoSpellingsOfTheSameAddressGiveTheSameValue(): void
    {
        $first = Email::parse('Bob@Example.com');
        $second = Email::parse(' bob@EXAMPLE.COM');

        self::assertInstanceOf(Email::class, $first);
        self::assertInstanceOf(Email::class, $second);
        self::assertSame($first->value(), $second->value());
    }

    public function testTheReasonCodeIsTheStableStringForTheFieldError(): void
    {
        $reason = Email::parse('nope');

        self::assertInstanceOf(InvalidEmail::class, $reason);
        self::assertSame('malformed', $reason->value);
    }

    /** A valid address of exactly $length bytes: a 64-byte local part and a domain of dot-separated labels. */
    private function addressOfLength(int $length): string
    {
        $domainLength = $length - 65;
        $domain = str_repeat(str_repeat('d', 63) . '.', 2) . str_repeat('t', $domainLength - 128);

        return str_repeat('l', 64) . '@' . $domain;
    }
}
