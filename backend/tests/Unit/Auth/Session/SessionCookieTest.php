<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Session;

use App\Auth\Session\SessionCookie;
use App\Auth\Session\SessionCookieState;
use App\Auth\Session\SessionToken;
use App\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionCookieTest extends TestCase
{
    private const VALUE = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';
    private const OTHER = 'ZYXWVUTSRQPONMLKJIHGFEDCBAzyxwvutsr-_01234';

    public function testIssueBuildsTheExactSetCookieValue(): void
    {
        $token = SessionToken::fromCookieValue(self::VALUE);
        self::assertNotNull($token);

        self::assertSame(
            '__Host-session=' . self::VALUE . '; Max-Age=604800; Path=/; Secure; HttpOnly; SameSite=Lax',
            SessionCookie::issue($token, 604800),
        );
    }

    public function testClearBuildsTheExactSetCookieValue(): void
    {
        self::assertSame('__Host-session=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax', SessionCookie::clear());
    }

    public function testIssuedCookieHasNoDomainAttribute(): void
    {
        self::assertStringNotContainsStringIgnoringCase('domain', SessionCookie::issue(SessionToken::generate(), 60));
        self::assertStringNotContainsStringIgnoringCase('domain', SessionCookie::clear());
    }

    #[DataProvider('invalidMaxAges')]
    public function testIssueRefusesANonPositiveLifetime(int $maxAge): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SessionCookie::issue(SessionToken::generate(), $maxAge);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidMaxAges(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'min int' => [PHP_INT_MIN];
    }

    public function testIssueAcceptsTheSmallestLifetime(): void
    {
        self::assertStringContainsString('; Max-Age=1; ', SessionCookie::issue(SessionToken::generate(), 1));
    }

    public function testReadWithoutACookieHeaderIsAbsent(): void
    {
        $read = SessionCookie::read(self::request(null));

        self::assertSame(SessionCookieState::Absent, $read->state);
        self::assertNull($read->token);
    }

    #[DataProvider('absentHeaders')]
    public function testReadWithoutOurCookieIsAbsent(string $header): void
    {
        $read = SessionCookie::read(self::request($header));

        self::assertSame(SessionCookieState::Absent, $read->state);
        self::assertNull($read->token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absentHeaders(): iterable
    {
        yield 'empty header' => [''];
        yield 'other cookies' => ['a=1; b=2'];
        yield 'different case is a different cookie' => ['__host-session=' . self::VALUE];
        yield 'upper case is a different cookie' => ['__HOST-SESSION=' . self::VALUE];
        yield 'prefix only' => ['__Host-sessions=' . self::VALUE];
        yield 'oversize header parses as empty' => ['__Host-session=' . self::VALUE . '; x=' . str_repeat('y', 8192)];
    }

    public function testReadReturnsThePresentToken(): void
    {
        $read = SessionCookie::read(self::request('a=1; __Host-session=' . self::VALUE . '; b=2'));

        self::assertSame(SessionCookieState::Present, $read->state);
        self::assertSame(self::VALUE, $read->token?->cookieValue());
    }

    public function testReadIgnoresADifferentlyCasedCookieNextToTheRealOne(): void
    {
        $read = SessionCookie::read(self::request('__host-session=' . self::OTHER . '; __Host-session=' . self::VALUE));

        self::assertSame(SessionCookieState::Present, $read->state);
        self::assertSame(self::VALUE, $read->token?->cookieValue());
    }

    #[DataProvider('malformedValues')]
    public function testReadWithABadValueIsMalformed(string $value): void
    {
        $read = SessionCookie::read(self::request('__Host-session=' . $value));

        self::assertSame(SessionCookieState::Malformed, $read->state);
        self::assertNull($read->token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedValues(): iterable
    {
        yield 'empty value' => [''];
        yield 'too short' => ['abc'];
        yield '42 characters' => [substr(self::VALUE, 0, 42)];
        yield '44 characters' => [self::VALUE . 'A'];
        yield 'quoted' => ['"' . self::VALUE . '"'];
        yield 'url-encoded character' => ['%61' . substr(self::VALUE, 1)];
        yield 'standard base64 plus' => ['+' . substr(self::VALUE, 1)];
    }

    #[DataProvider('duplicateHeaders')]
    public function testReadWithTwoOrMoreOfOurCookiesIsDuplicate(string $header): void
    {
        $read = SessionCookie::read(self::request($header));

        self::assertSame(SessionCookieState::Duplicate, $read->state);
        self::assertNull($read->token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateHeaders(): iterable
    {
        yield 'two different valid values' => ['__Host-session=' . self::VALUE . '; __Host-session=' . self::OTHER];
        yield 'two equal valid values' => ['__Host-session=' . self::VALUE . '; __Host-session=' . self::VALUE];
        yield 'valid and malformed' => ['__Host-session=' . self::VALUE . '; __Host-session=x'];
        yield 'two malformed' => ['__Host-session=; __Host-session='];
        yield 'three with others between' => ['__Host-session=a; x=1; __Host-session=b; __Host-session=c'];
    }

    public function testReadStaysCaseInsensitiveOnTheHeaderNameOnly(): void
    {
        $request = new Request('GET', '/', [], ['cookie' => '__Host-session=' . self::VALUE], '');

        self::assertSame(SessionCookieState::Present, SessionCookie::read($request)->state);
    }

    private static function request(?string $cookie): Request
    {
        return new Request('GET', '/', [], $cookie === null ? [] : ['cookie' => $cookie], '');
    }

    public function testThePrivateBuilderThatTakesTheRawValueIsMarkedSensitive(): void
    {
        $method = new \ReflectionMethod(SessionCookie::class, 'build');

        self::assertCount(1, $method->getParameters()[0]->getAttributes(\SensitiveParameter::class));
    }
}
