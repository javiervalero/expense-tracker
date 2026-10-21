<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Throttle;

use App\Auth\Email;
use App\Auth\Throttle\LoginAttempt;
use App\Auth\Throttle\LoginThrottle;
use App\Http\ClientAddress;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\InMemoryThrottleStore;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * A LoginAttempt is a one-shot proof; copying or round-tripping it would let one counted attempt be refunded twice.
 */
final class LoginAttemptTest extends TestCase
{
    private function issueAttempt(): LoginAttempt
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $throttle = new LoginThrottle(new InMemoryThrottleStore(), new JsonLogger($stream));
        $email = Email::parse('ana@example.com');
        self::assertInstanceOf(Email::class, $email);
        $attempt = $throttle->attempt($email, ClientAddress::of('192.0.2.7', '192.0.2.7'))->attempt;
        self::assertInstanceOf(LoginAttempt::class, $attempt);

        return $attempt;
    }

    public function testCloningIsRefused(): void
    {
        $attempt = $this->issueAttempt();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A login attempt must not be cloned.');
        // The clone itself must throw, so its result is never used.
        /** @phpstan-ignore expr.resultUnused */
        clone $attempt;
    }

    public function testSerializingIsRefused(): void
    {
        $attempt = $this->issueAttempt();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A login attempt must not be serialised.');
        serialize($attempt);
    }

    public function testUnserializingIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A login attempt must not be unserialised.');
        unserialize('O:' . strlen(LoginAttempt::class) . ':"' . LoginAttempt::class . '":0:{}');
    }

    public function testDumpsShowNothing(): void
    {
        $attempt = $this->issueAttempt();

        ob_start();
        var_dump($attempt);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(hash('sha256', 'ana@example.com'), $dump);
        self::assertStringNotContainsString('buckets', $dump);
        self::assertStringNotContainsString('LOGIN', $dump);
        self::assertStringNotContainsString('LOGIN', print_r($attempt, true));
    }
}
