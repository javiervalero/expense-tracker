<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Session;

use App\Auth\Session\CarriedSession;
use App\Http\Request;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

final class CarriedSessionTest extends TestCase
{
    private const USER_ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';

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

    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    /**
     * @param array<string, string> $headers
     */
    private function end(array $headers): void
    {
        (new CarriedSession($this->store, new JsonLogger($this->log)))->end(new Request('POST', '/api/v1/auth/register', [], $headers, '', [Request::REQUEST_ID => 'req-1']));
    }

    public function testItDeletesTheSessionNamedByAWellFormedCookie(): void
    {
        $token = $this->store->seed(self::USER_ID);

        $this->end(['cookie' => 'theme=dark; __Host-session=' . $token->cookieValue()]);

        self::assertSame([], $this->store->sessions);
    }

    public function testItDeletesNothingWithoutACookieOrWithAnotherOne(): void
    {
        $token = $this->store->seed(self::USER_ID);

        $this->end([]);
        $this->end(['cookie' => 'theme=dark']);

        self::assertArrayHasKey($token->hash(), $this->store->sessions);
        self::assertSame(0, $this->store->calls);
    }

    public function testItDeletesNothingForAMalformedOrRepeatedCookie(): void
    {
        $token = $this->store->seed(self::USER_ID);

        $this->end(['cookie' => '__Host-session=short']);
        $this->end(['cookie' => '__Host-session=' . $token->cookieValue() . '; __Host-session=' . $token->cookieValue()]);

        self::assertArrayHasKey($token->hash(), $this->store->sessions);
        self::assertSame(0, $this->store->calls);
    }

    public function testAnUnknownSessionIsNotAnError(): void
    {
        $other = new InMemorySessionStore(new FrozenClock());
        $token = $other->seed(self::USER_ID);

        $this->end(['cookie' => '__Host-session=' . $token->cookieValue()]);

        self::assertSame(1, $this->store->calls);
    }

    public function testARepeatedCookieIsLoggedAsAWarningWithTheRequestIdAndNoValues(): void
    {
        $token = $this->store->seed(self::USER_ID);

        $this->end(['cookie' => '__Host-session=' . $token->cookieValue() . '; __Host-session=' . $token->cookieValue()]);

        self::assertStringContainsString('"level":"warning"', $this->logged());
        self::assertStringContainsString('"message":"duplicate session cookie"', $this->logged());
        self::assertStringContainsString('"requestId":"req-1"', $this->logged());
        self::assertStringNotContainsString($token->cookieValue(), $this->logged());
    }

    public function testOtherCookieStatesLogNothing(): void
    {
        $this->end([]);
        $this->end(['cookie' => '__Host-session=short']);

        self::assertSame('', $this->logged());
    }

    public function testEndBestEffortDeletesTheSessionLikeEnd(): void
    {
        $token = $this->store->seed(self::USER_ID);

        $this->endBestEffort(['cookie' => '__Host-session=' . $token->cookieValue()]);

        self::assertSame([], $this->store->sessions);
    }

    public function testEndBestEffortLogsAStorageFailureWithoutTheTokenAndDoesNotThrow(): void
    {
        $token = $this->store->seed(self::USER_ID);
        $this->store->deleteFailure = new StorageFailure('DeleteItem', 'InternalServerError');

        $this->endBestEffort(['cookie' => '__Host-session=' . $token->cookieValue()]);

        $log = $this->logged();
        self::assertStringContainsString('could not end the carried session', $log);
        self::assertStringContainsString('req-1', $log);
        self::assertStringContainsString('DeleteItem', $log);
        self::assertStringContainsString('InternalServerError', $log);
        self::assertStringNotContainsString($token->cookieValue(), $log);
    }

    /**
     * @param array<string, string> $headers
     */
    private function endBestEffort(array $headers): void
    {
        (new CarriedSession($this->store, new JsonLogger($this->log)))->endBestEffort(new Request('POST', '/api/v1/auth/login', [], $headers, '', [Request::REQUEST_ID => 'req-1']));
    }
}
