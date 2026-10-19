<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Session;

use App\Auth\Session\Authentication;
use App\Auth\Session\Principal;
use App\Auth\Session\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const HASH = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';
    private const NOW = 1_800_000_000;

    public function testAStartedSessionExpiresAfterTheIdleTimeAndCapsAtTheAbsoluteTime(): void
    {
        $session = Session::start(self::ID, self::HASH, self::NOW);

        self::assertSame(self::NOW, $session->createdAt);
        self::assertSame(self::NOW, $session->renewedAt);
        self::assertSame(self::NOW + 86_400, $session->expiresAt);
        self::assertSame(self::NOW + 604_800, $session->absoluteExpiresAt);
    }

    public function testItIsActiveOnlyBeforeBothExpiryTimes(): void
    {
        $session = Session::start(self::ID, self::HASH, self::NOW);

        self::assertTrue($session->isActiveAt(self::NOW + 86_399));
        self::assertFalse($session->isActiveAt(self::NOW + 86_400));
        $renewed = new Session(self::ID, self::HASH, self::NOW, self::NOW + 80_000, self::NOW + 100_000, self::NOW + 90_000);
        self::assertTrue($renewed->isActiveAt(self::NOW + 89_999));
        self::assertFalse($renewed->isActiveAt(self::NOW + 90_000));
    }

    public function testRenewalMovesTheIdleExpiryButNeverPastTheAbsoluteCap(): void
    {
        $session = new Session(self::ID, self::HASH, self::NOW, self::NOW, self::NOW + 100_000, self::NOW + 86_400);

        $renewed = $session->renewed(self::NOW + 20_000);

        self::assertSame(self::NOW + 20_000, $renewed->renewedAt);
        self::assertSame(self::NOW + 100_000, $renewed->expiresAt);
        self::assertSame($session->absoluteExpiresAt, $renewed->absoluteExpiresAt);
        self::assertSame($session->createdAt, $renewed->createdAt);
    }

    public function testRenewalIsDueOnlyFifteenMinutesAfterTheLastOne(): void
    {
        $session = Session::start(self::ID, self::HASH, self::NOW);

        self::assertFalse($session->dueForRenewal(self::NOW + 899));
        self::assertTrue($session->dueForRenewal(self::NOW + 900));
        self::assertFalse($session->dueForRenewal(self::NOW - 5));
    }

    public function testRenewalIsNotDueWhenItWouldNotExtendTheSession(): void
    {
        $session = new Session(self::ID, self::HASH, self::NOW, self::NOW, self::NOW + 1_000, self::NOW + 1_000);

        self::assertFalse($session->dueForRenewal(self::NOW + 900));
    }

    public function testThePrincipalHidesTheTokenHashFromDebugOutput(): void
    {
        $principal = new Principal(self::ID, self::HASH);

        self::assertStringNotContainsString(self::HASH, print_r($principal, true));
        self::assertSame(self::ID, $principal->userId);
    }

    public function testTheSessionHidesTheTokenHashFromDebugOutput(): void
    {
        $session = Session::start(self::ID, self::HASH, self::NOW);

        self::assertStringNotContainsString(self::HASH, print_r($session, true));
    }

    public function testAnAuthenticatedResultHasNoStaleCookieToAskAbout(): void
    {
        $result = Authentication::authenticated(new Principal(self::ID, self::HASH));

        $this->expectException(\LogicException::class);

        $result->cookieWasSent();
    }
}
