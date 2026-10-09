<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Bootstrap;

use LocalAws\Bootstrap\ServiceNotReady;
use LocalAws\Bootstrap\ServiceWaiter;
use LocalAws\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;

final class ServiceWaiterTest extends TestCase
{
    private float $now = 1000.0;
    /** @var list<float> */
    private array $sleeps = [];
    /** @var list<string> */
    private array $logLines = [];

    private function waiter(): ServiceWaiter
    {
        $this->now = 1000.0;
        $this->sleeps = [];
        $this->logLines = [];

        return new ServiceWaiter(
            new JsonLogger(
                function (string $line): void {
                    $this->logLines[] = $line;
                },
                static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-10-09T12:00:00+00:00'),
            ),
            fn(): float => $this->now,
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += $seconds;
            },
        );
    }

    public function testReturnsAtOnceWhenTheServiceIsAlreadyUp(): void
    {
        $waiter = $this->waiter();
        $attempts = 0;

        $waiter->waitFor('s3', static function () use (&$attempts): void {
            ++$attempts;
        }, 30);

        self::assertSame(1, $attempts);
        self::assertSame([], $this->sleeps);
    }

    public function testRetriesWithAPauseUntilTheProbeSucceeds(): void
    {
        $waiter = $this->waiter();
        $attempts = 0;

        $waiter->waitFor('s3', static function () use (&$attempts): void {
            ++$attempts;
            if ($attempts < 3) {
                throw new \RuntimeException('connection refused');
            }
        }, 30);

        self::assertSame(3, $attempts);
        self::assertSame([0.5, 0.5], $this->sleeps);
    }

    public function testThrowsServiceNotReadyNamingTheServiceAndTheLastErrorOnTimeout(): void
    {
        $waiter = $this->waiter();

        try {
            $waiter->waitFor('s3', static function (): void {
                throw new \RuntimeException('connection refused');
            }, 2);
            self::fail('a timeout must throw');
        } catch (ServiceNotReady $e) {
            self::assertStringContainsString('s3', $e->getMessage());
            self::assertStringContainsString('2 seconds', $e->getMessage());
            self::assertStringContainsString('connection refused', $e->getMessage());
        }

        self::assertCount(4, $this->sleeps, 'a 2 s timeout with 0.5 s pauses sleeps four times');
    }

    public function testTriesOnceEvenWithAZeroTimeout(): void
    {
        $waiter = $this->waiter();
        $attempts = 0;

        $this->expectException(ServiceNotReady::class);
        try {
            $waiter->waitFor('s3', static function () use (&$attempts): void {
                ++$attempts;
                throw new \RuntimeException('down');
            }, 0);
        } finally {
            self::assertSame(1, $attempts);
            self::assertSame([], $this->sleeps);
        }
    }

    public function testLogsWaitingAndReadyEvents(): void
    {
        $waiter = $this->waiter();
        $attempts = 0;

        $waiter->waitFor('s3', static function () use (&$attempts): void {
            ++$attempts;
            if ($attempts < 2) {
                throw new \RuntimeException('not yet');
            }
        }, 30);

        $messages = array_map(
            static function (string $line): string {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                self::assertIsString($decoded['message']);

                return $decoded['message'];
            },
            $this->logLines,
        );
        self::assertSame(['waiting for service', 'service ready'], $messages);
    }

    public function testTheSystemClockWaiterPausesForRealAndRetries(): void
    {
        $waiter = ServiceWaiter::withSystemClock(new JsonLogger(static function (string $line): void {}));
        $attempts = 0;
        $start = microtime(true);

        $waiter->waitFor('self', static function () use (&$attempts): void {
            ++$attempts;
            if ($attempts < 2) {
                throw new \RuntimeException('first try fails');
            }
        }, 5);

        self::assertSame(2, $attempts);
        self::assertGreaterThanOrEqual(0.4, microtime(true) - $start, 'it must really pause between the tries');
    }
}
