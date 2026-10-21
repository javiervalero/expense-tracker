<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Session;

use App\Auth\Session\Principal;
use App\Auth\Session\RequireSession;
use App\Auth\Session\SessionAuthenticator;
use App\Auth\Session\SessionToken;
use App\Http\Handler;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

final class RequireSessionTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const CLEARING = '__Host-session=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax';

    private InMemorySessionStore $store;
    private int $innerCalls = 0;
    private ?Request $seen = null;
    private RequireSession $guard;

    protected function setUp(): void
    {
        $clock = new FrozenClock();
        $this->store = new InMemorySessionStore($clock);
        $log = fopen('php://memory', 'w+b');
        self::assertIsResource($log);
        $inner = new class ($this) implements Handler {
            public function __construct(private readonly RequireSessionTest $test) {}

            public function handle(Request $request): Response
            {
                $this->test->reached($request);

                return Response::empty(204);
            }
        };
        $this->guard = new RequireSession($inner, new SessionAuthenticator($this->store, $clock, new JsonLogger($log)));
    }

    public function reached(Request $request): void
    {
        ++$this->innerCalls;
        $this->seen = $request;
    }

    private static function request(?string $cookie): Request
    {
        return new Request('GET', '/api/v1/auth/me', [], $cookie === null ? [] : ['cookie' => $cookie], '');
    }

    public function testNoCookieGives401WithTheChallengeAndNoClearingCookie(): void
    {
        $response = $this->guard->handle(self::request(null));

        self::assertSame(401, $response->status);
        self::assertSame(['Session'], $response->headerValues('WWW-Authenticate'));
        self::assertSame([], $response->headerValues('Set-Cookie'));
        self::assertSame('application/problem+json', $response->headerValues('Content-Type')[0]);
        self::assertSame(0, $this->innerCalls);
    }

    public function testAMalformedCookieGives401WithTheClearingCookie(): void
    {
        $response = $this->guard->handle(self::request('__Host-session=short'));

        self::assertSame(401, $response->status);
        self::assertSame(['Session'], $response->headerValues('WWW-Authenticate'));
        self::assertSame([self::CLEARING], $response->headerValues('Set-Cookie'));
        self::assertSame(0, $this->innerCalls);
    }

    public function testAnUnknownCookieGives401WithTheClearingCookie(): void
    {
        $response = $this->guard->handle(self::request('__Host-session=' . SessionToken::generate()->cookieValue()));

        self::assertSame(401, $response->status);
        self::assertSame([self::CLEARING], $response->headerValues('Set-Cookie'));
        self::assertSame(0, $this->innerCalls);
    }

    public function testADuplicateCookieGives401WithTheClearingCookieAndNoLookup(): void
    {
        $a = SessionToken::generate()->cookieValue();
        $b = SessionToken::generate()->cookieValue();

        $response = $this->guard->handle(self::request('__Host-session=' . $a . '; __Host-session=' . $b));

        self::assertSame(401, $response->status);
        self::assertSame([self::CLEARING], $response->headerValues('Set-Cookie'));
        self::assertSame(0, $this->store->calls);
        self::assertSame(0, $this->innerCalls);
    }

    public function testTheProblemBodyNamesNothingAboutWhyTheSessionFailed(): void
    {
        $unknown = $this->guard->handle(self::request('__Host-session=' . SessionToken::generate()->cookieValue()));
        $absent = $this->guard->handle(self::request(null));

        self::assertSame($absent->body, $unknown->body);
    }

    public function testAValidSessionSetsThePrincipalAndRecordsTheUserIdThenCallsTheHandler(): void
    {
        $token = $this->store->create(self::ID);
        $context = new RequestLogContext();
        $request = self::request('__Host-session=' . $token->cookieValue())->withAttribute(RequestLogContext::ATTRIBUTE, $context);

        $response = $this->guard->handle($request);

        self::assertSame(204, $response->status);
        self::assertSame(1, $this->innerCalls);
        $principal = $this->seen?->attribute(Principal::ATTRIBUTE);
        self::assertInstanceOf(Principal::class, $principal);
        self::assertSame(self::ID, $principal->userId);
        self::assertSame($token->hash(), $principal->tokenHash);
        self::assertSame(self::ID, $context->userId());
    }

    public function testAValidSessionWorksWithoutALogContext(): void
    {
        $token = $this->store->create(self::ID);

        $response = $this->guard->handle(self::request('__Host-session=' . $token->cookieValue()));

        self::assertSame(204, $response->status);
    }
}
