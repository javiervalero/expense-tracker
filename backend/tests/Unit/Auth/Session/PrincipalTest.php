<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Session;

use App\Auth\Session\Principal;
use App\Http\Request;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PrincipalTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testOfReturnsThePrincipalPutOnTheRequestByTheGuard(): void
    {
        $principal = new Principal(self::ID, self::HASH);
        $request = (new Request('GET', '/x', [], [], ''))->withAttribute(Principal::ATTRIBUTE, $principal);

        self::assertSame($principal, Principal::of($request));
    }

    public function testAMissingPrincipalIsAMisconfiguredGuardNotAnAnonymousCaller(): void
    {
        $this->expectException(LogicException::class);

        Principal::of(new Request('GET', '/x', [], [], ''));
    }

    public function testSomethingElseUnderTheAttributeIsRefused(): void
    {
        $request = (new Request('GET', '/x', [], [], ''))->withAttribute(Principal::ATTRIBUTE, ['userId' => self::ID]);

        $this->expectException(LogicException::class);

        Principal::of($request);
    }

    public function testDebugInfoRedactsTheTokenHash(): void
    {
        self::assertSame(['userId' => self::ID, 'tokenHash' => '[redacted]'], (new Principal(self::ID, self::HASH))->__debugInfo());
    }

    public function testThePrincipalCannotBeCloned(): void
    {
        $this->expectException(LogicException::class);

        $copy = clone new Principal(self::ID, self::HASH);
        unset($copy);
    }

    public function testThePrincipalCannotBeClonedWithReplacedProperties(): void
    {
        $this->expectException(LogicException::class);

        // PHPStan reads the clone-with as an assignment to a protected(set) property; the point is that it throws.
        // @phpstan-ignore assign.propertyProtectedSet
        $copy = clone(new Principal(self::ID, self::HASH), ['userId' => '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10']);
        unset($copy);
    }

    public function testThePrincipalCannotBeUnserialised(): void
    {
        $this->expectException(LogicException::class);

        (new \ReflectionClass(Principal::class))->newInstanceWithoutConstructor()->__unserialize(['userId' => self::ID]);
    }

    public function testThePrincipalCannotBeSerialisedEither(): void
    {
        $this->expectException(LogicException::class);

        serialize(new Principal(self::ID, self::HASH));
    }

    public function testThePrincipalIsFinalSoNoSubclassCanBeInstantiatedPastTheConstructionGuard(): void
    {
        // PrincipalConstructionTest does not flag `new class (...) extends Principal {}`; `final` is what refuses it.
        self::assertTrue((new \ReflectionClass(Principal::class))->isFinal());
    }
}
