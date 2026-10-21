<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Http;

use App\Auth\Http\LogoutHandler;
use App\Auth\Session\CarriedSession;
use App\Auth\Session\SessionToken;
use App\Http\Request;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

final class LogoutHandlerTest extends TestCase
{
    private const USER_ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const CLEARING = '__Host-session=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax';

    private InMemorySessionStore $store;

    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $this->store = new InMemorySessionStore(new FrozenClock());
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->log = $stream;
    }

    private function logout(?string $cookie): \App\Http\Response
    {
        $handler = new LogoutHandler(new CarriedSession($this->store, new JsonLogger($this->log)));

        return $handler->handle(new Request('POST', '/api/v1/auth/logout', [], $cookie === null ? [] : ['cookie' => $cookie], '', [Request::REQUEST_ID => 'req-1']));
    }

    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    public function testItDeletesTheSessionAndAnswers204WithTheClearingCookie(): void
    {
        $token = $this->store->seed(self::USER_ID);

        $response = $this->logout('__Host-session=' . $token->cookieValue());

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame([self::CLEARING], $response->headerValues('Set-Cookie'));
        self::assertSame([], $this->store->sessions);
    }

    public function testWithoutACookieItStillAnswers204WithTheClearingCookieAndTouchesNoStorage(): void
    {
        $response = $this->logout(null);

        self::assertSame(204, $response->status);
        self::assertSame([self::CLEARING], $response->headerValues('Set-Cookie'));
        self::assertSame([], $this->store->operations);
    }

    public function testAnUnknownOrMalformedCookieGetsTheSameAnswerAsAKnownOne(): void
    {
        $token = $this->store->seed(self::USER_ID);
        $known = $this->logout('__Host-session=' . $token->cookieValue());

        $unknown = $this->logout('__Host-session=' . SessionToken::generate()->cookieValue());
        $malformed = $this->logout('__Host-session=short');
        $none = $this->logout(null);

        foreach ([$unknown, $malformed, $none] as $response) {
            self::assertSame($known->status, $response->status);
            self::assertSame($known->headers, $response->headers);
            self::assertSame($known->body, $response->body);
        }
    }

    public function testADuplicateCookieDeletesNothingAndIsLoggedWithoutValues(): void
    {
        $kept = $this->store->seed(self::USER_ID);
        $other = SessionToken::generate();

        $response = $this->logout('__Host-session=' . $kept->cookieValue() . '; __Host-session=' . $other->cookieValue());

        self::assertSame(204, $response->status);
        self::assertCount(1, $this->store->sessions);
        self::assertStringContainsString('duplicate session cookie', $this->logged());
        self::assertStringNotContainsString($kept->cookieValue(), $this->logged());
        self::assertStringNotContainsString($other->cookieValue(), $this->logged());
    }

    public function testAStorageFailureIsNotSwallowedSoTheClientNeverThinksItIsSignedOut(): void
    {
        $token = $this->store->seed(self::USER_ID);
        $this->store->deleteFailure = new StorageFailure('DeleteItem', 'InternalServerError');

        $this->expectException(StorageFailure::class);

        $this->logout('__Host-session=' . $token->cookieValue());
    }
}
