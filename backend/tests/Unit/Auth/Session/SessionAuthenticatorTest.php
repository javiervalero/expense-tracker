<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Session;

use App\Auth\Session\AnonymousReason;
use App\Auth\Session\Authentication;
use App\Auth\Session\Session;
use App\Auth\Session\SessionAuthenticator;
use App\Auth\Session\SessionLifetime;
use App\Auth\Session\SessionStore;
use App\Auth\Session\SessionToken;
use App\Http\Request;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

final class SessionAuthenticatorTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';

    private FrozenClock $clock;
    private InMemorySessionStore $store;
    private SessionAuthenticator $authenticator;
    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $log = fopen('php://memory', 'w+b');
        self::assertIsResource($log);
        $this->log = $log;
        $this->clock = new FrozenClock('2026-10-16T12:00:00+00:00');
        $this->store = new InMemorySessionStore($this->clock);
        $this->authenticator = new SessionAuthenticator($this->store, $this->clock, new JsonLogger($log));
    }

    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    private static function requestWithCookieHeader(?string $header): Request
    {
        return new Request('GET', '/api/v1/auth/me', [], $header === null ? [] : ['cookie' => $header], '');
    }

    private static function requestWith(SessionToken $token): Request
    {
        return self::requestWithCookieHeader('__Host-session=' . $token->cookieValue());
    }

    private function newSession(): SessionToken
    {
        return $this->store->seed(self::ID);
    }

    private function assertAnonymous(AnonymousReason $reason, Authentication $result): void
    {
        self::assertNull($result->principal);
        self::assertSame($reason, $result->reason);
    }

    public function testAValidSessionIsAuthenticatedAsItsPrincipal(): void
    {
        $token = $this->newSession();

        $result = $this->authenticator->authenticate(self::requestWith($token));

        self::assertNull($result->reason);
        self::assertSame(self::ID, $result->principal?->userId);
        self::assertSame($token->hash(), $result->principal->tokenHash);
    }

    public function testAnAbsentCookieIsAnonymousWithZeroRepositoryCalls(): void
    {
        $result = $this->authenticator->authenticate(self::requestWithCookieHeader(null));

        $this->assertAnonymous(AnonymousReason::NoCookie, $result);
        self::assertFalse($result->cookieWasSent());
        self::assertSame(0, $this->store->calls);
    }

    public function testAnotherCookieNameIsAbsentToo(): void
    {
        $result = $this->authenticator->authenticate(self::requestWithCookieHeader('theme=dark'));

        $this->assertAnonymous(AnonymousReason::NoCookie, $result);
        self::assertSame(0, $this->store->calls);
    }

    public function testAMalformedCookieIsAnonymousWithZeroRepositoryCalls(): void
    {
        $result = $this->authenticator->authenticate(self::requestWithCookieHeader('__Host-session=too-short'));

        $this->assertAnonymous(AnonymousReason::MalformedCookie, $result);
        self::assertTrue($result->cookieWasSent());
        self::assertSame(0, $this->store->calls);
    }

    public function testADuplicateCookieIsAnonymousWithZeroCallsAndAWarningWithoutValues(): void
    {
        $good = $this->newSession();
        $other = SessionToken::generate();
        $header = '__Host-session=' . $good->cookieValue() . '; __Host-session=' . $other->cookieValue();

        $result = $this->authenticator->authenticate(self::requestWithCookieHeader($header)->withAttribute(Request::REQUEST_ID, 'rid9'));

        $this->assertAnonymous(AnonymousReason::DuplicateCookie, $result);
        self::assertTrue($result->cookieWasSent());
        self::assertSame(0, $this->store->calls);
        $entry = json_decode($this->logged(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);
        self::assertSame('warning', $entry['level']);
        self::assertSame('duplicate session cookie', $entry['message']);
        self::assertSame('rid9', $entry['requestId']);
        self::assertStringNotContainsString($good->cookieValue(), $this->logged());
        self::assertStringNotContainsString($other->cookieValue(), $this->logged());
    }

    public function testAnUnknownSessionIsAnonymous(): void
    {
        $result = $this->authenticator->authenticate(self::requestWith(SessionToken::generate()));

        $this->assertAnonymous(AnonymousReason::UnknownSession, $result);
        self::assertTrue($result->cookieWasSent());
        self::assertSame(1, $this->store->calls);
    }

    public function testAnExpiredSessionTheStoreDoesNotReturnIsUnknown(): void
    {
        $token = $this->newSession();
        $this->clock->advance(SessionLifetime::IDLE + 1);

        $result = $this->authenticator->authenticate(self::requestWith($token));

        $this->assertAnonymous(AnonymousReason::UnknownSession, $result);
        self::assertSame(0, $this->store->renewCalls);
    }

    public function testAnExpiredSessionAMisbehavingStoreReturnsIsStillExpired(): void
    {
        $token = $this->newSession();
        $this->store->findIgnoresExpiry = true;
        $this->clock->advance(SessionLifetime::IDLE + 1);

        $result = $this->authenticator->authenticate(self::requestWith($token));

        $this->assertAnonymous(AnonymousReason::ExpiredSession, $result);
        self::assertSame(0, $this->store->renewCalls);
    }

    public function testTheAbsoluteCapReachedRightAfterARenewalIsAnonymous(): void
    {
        $token = $this->newSession();
        $this->store->findIgnoresExpiry = true;
        $now = $this->clock->now()->getTimestamp();
        $this->store->put($token->hash(), new Session(self::ID, $token->hash(), $now - 1000, $now - 1000, $now + 1000, $now + 500));
        $this->clock->advance(10);
        self::assertNotNull($this->authenticator->authenticate(self::requestWith($token))->principal);
        $this->clock->advance(990);

        $result = $this->authenticator->authenticate(self::requestWith($token));

        $this->assertAnonymous(AnonymousReason::ExpiredSession, $result);
    }

    public function testThereIsNoRenewalBeforeFifteenMinutesAndOneAfter(): void
    {
        $token = $this->newSession();

        $this->clock->advance(899);
        $this->authenticator->authenticate(self::requestWith($token));
        self::assertSame(0, $this->store->renewCalls);

        $this->clock->advance(1);
        $this->authenticator->authenticate(self::requestWith($token));
        self::assertSame(1, $this->store->renewCalls);
        self::assertSame($this->clock->now()->getTimestamp(), $this->store->sessions[$token->hash()]->renewedAt);

        $this->clock->advance(60);
        $this->authenticator->authenticate(self::requestWith($token));
        self::assertSame(1, $this->store->renewCalls);
    }

    public function testRenewalNeverGoesPastTheAbsoluteCap(): void
    {
        $token = $this->newSession();
        $now = $this->clock->now()->getTimestamp();
        $absolute = $now + 1000;
        $this->store->put($token->hash(), new Session(self::ID, $token->hash(), $now - 5000, $now - 5000, $absolute, $now + 800));

        $this->authenticator->authenticate(self::requestWith($token));

        self::assertSame(1, $this->store->renewCalls);
        self::assertSame($absolute, $this->store->sessions[$token->hash()]->expiresAt);
    }

    public function testARenewalThatFindsTheSessionGoneIsAnonymous(): void
    {
        $token = $this->newSession();
        $this->clock->advance(900);
        $this->store->renewFindsNothing = true;

        $result = $this->authenticator->authenticate(self::requestWith($token));

        $this->assertAnonymous(AnonymousReason::SessionEnded, $result);
        self::assertTrue($result->cookieWasSent());
    }

    public function testAFailedRenewalIsLoggedAndTheRequestStillAuthenticates(): void
    {
        $token = $this->newSession();
        $this->clock->advance(900);
        $this->store->renewFails = true;

        $result = $this->authenticator->authenticate(self::requestWith($token));

        self::assertSame(self::ID, $result->principal?->userId);
        self::assertStringContainsString('session renewal failed', $this->logged());
        self::assertStringContainsString('ThrottlingException', $this->logged());
        self::assertStringNotContainsString($token->cookieValue(), $this->logged());
        self::assertStringNotContainsString($token->hash(), $this->logged());
    }

    public function testACorruptItemFoundByTheRenewalIsRethrownNotLogged(): void
    {
        $token = $this->newSession();
        $this->clock->advance(900);
        $this->store->renewFailure = new StorageFailure('UpdateItem', SessionStore::CORRUPT_ITEM);

        try {
            $this->authenticator->authenticate(self::requestWith($token));
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame(SessionStore::CORRUPT_ITEM, $failure->awsErrorCode);
        }
        self::assertSame('', $this->logged());
    }

    public function testAnAuthenticatedResultIsNeverReportedAsAStaleCookie(): void
    {
        $result = $this->authenticator->authenticate(self::requestWith($this->newSession()));

        $this->expectException(\LogicException::class);

        $result->cookieWasSent();
    }
}
