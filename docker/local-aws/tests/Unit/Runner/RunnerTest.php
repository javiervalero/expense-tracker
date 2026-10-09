<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use LocalAws\Logging\JsonLogger;
use LocalAws\Runner\BatchResultInterpreter;
use LocalAws\Runner\InvocationFailed;
use LocalAws\Runner\InvocationResult;
use LocalAws\Runner\QueueMessage;
use LocalAws\Runner\Runner;
use LocalAws\Runner\SqsEventBuilder;
use LocalAws\Runner\StopSignal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    private const SECRET_BODY = 'SECRET-RECEIPT-BODY';

    /** @var list<string> */
    private array $logLines = [];
    /** @var list<int> */
    private array $sleeps = [];
    private StopSignal $stop;

    protected function setUp(): void
    {
        $this->logLines = [];
        $this->sleeps = [];
        $this->stop = new StopSignal();
    }

    private static function message(string $id): QueueMessage
    {
        return new QueueMessage($id, 'handle-' . $id, self::SECRET_BODY . '-' . $id, 'md5-' . $id, ['ApproximateReceiveCount' => '1'], []);
    }

    /**
     * @return list<QueueMessage>
     */
    private static function batch(string ...$ids): array
    {
        return array_map(self::message(...), array_values($ids));
    }

    private static function ok(string $body = '{"batchItemFailures":[]}'): InvocationResult
    {
        return new InvocationResult(200, $body);
    }

    private function runner(FakeQueueClient $queue, FakeInvoker $invoker): Runner
    {
        return new Runner(
            $queue,
            $invoker,
            new SqsEventBuilder('eu-west-1', 'arn:aws:sqs:eu-west-1:000000000000:receipts'),
            new BatchResultInterpreter(),
            new JsonLogger(function (string $line): void {
                $this->logLines[] = $line;
            }),
            $this->stop,
            function (int $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function logs(): array
    {
        return array_map(Decoded::object(...), $this->logLines);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string> the log messages, in order
     */
    private function logMessages(): array
    {
        return array_map(static fn(array $entry): string => self::text($entry['message'] ?? null), $this->logs());
    }

    public function testDeletesEveryMessageOfASuccessfulBatch(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1', 'm-2', 'm-3')]);
        $invoker = new FakeInvoker(self::ok());

        $this->runner($queue, $invoker)->runOnce();

        self::assertSame(['handle-m-1', 'handle-m-2', 'handle-m-3'], $queue->deletedReceiptHandles);
        self::assertCount(1, $invoker->payloads);
        self::assertSame(['m-1', 'm-2', 'm-3'], array_column(Decoded::records($invoker->payloads[0]), 'messageId'));
    }

    public function testDeletesOnlyTheMessagesThatDidNotFail(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1', 'm-2', 'm-3')]);
        $invoker = new FakeInvoker(self::ok('{"batchItemFailures":[{"itemIdentifier":"m-2"}]}'));

        $this->runner($queue, $invoker)->runOnce();

        self::assertSame(['handle-m-1', 'handle-m-3'], $queue->deletedReceiptHandles);
        $warnings = array_values(array_filter($this->logs(), static fn(array $entry): bool => $entry['level'] === 'warning'));
        self::assertCount(1, $warnings);
        self::assertSame(['m-2'], $warnings[0]['failedMessageIds']);
    }

    public function testDeletesNothingWhenEveryMessageFailed(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1', 'm-2')]);
        $invoker = new FakeInvoker(self::ok('{"batchItemFailures":[{"itemIdentifier":"m-1"},{"itemIdentifier":"m-2"}]}'));

        $this->runner($queue, $invoker)->runOnce();

        self::assertSame(0, $queue->deleteCalls);
    }

    /**
     * @return iterable<string, array{InvocationResult|\Throwable}>
     */
    public static function failedInvocations(): iterable
    {
        yield 'HTTP 500' => [new InvocationResult(500, 'oops')];
        yield 'HTTP 502 with no body' => [new InvocationResult(502, '')];
        yield 'RIE error payload with HTTP 200' => [new InvocationResult(200, '{"errorType":"RuntimeException","errorMessage":"boom"}')];
        yield 'invalid JSON' => [new InvocationResult(200, 'not json')];
        yield 'unknown itemIdentifier' => [new InvocationResult(200, '{"batchItemFailures":[{"itemIdentifier":"ghost"}]}')];
        yield 'empty itemIdentifier' => [new InvocationResult(200, '{"batchItemFailures":[{"itemIdentifier":""}]}')];
        yield 'transport failure (timeout)' => [new InvocationFailed('timed out')];
    }

    #[DataProvider('failedInvocations')]
    public function testDeletesNothingWhenTheInvocationFailsAsAWhole(InvocationResult|\Throwable $answer): void
    {
        $queue = new FakeQueueClient([self::batch('m-1', 'm-2')]);

        $this->runner($queue, new FakeInvoker($answer))->runOnce();

        self::assertSame(0, $queue->deleteCalls);
        self::assertSame([], $queue->deletedReceiptHandles);
        $errors = array_values(array_filter($this->logs(), static fn(array $entry): bool => $entry['level'] === 'error'));
        self::assertCount(1, $errors);
        self::assertSame(['m-1', 'm-2'], $errors[0]['messageIds']);
    }

    public function testAnEmptyPollInvokesAndDeletesNothing(): void
    {
        $queue = new FakeQueueClient([]);
        $invoker = new FakeInvoker(self::ok());

        $this->runner($queue, $invoker)->runOnce();

        self::assertSame(1, $queue->receiveCalls);
        self::assertSame([], $invoker->payloads);
        self::assertSame(0, $queue->deleteCalls);
        self::assertSame([], $this->logLines);
    }

    public function testAPartialDeleteFailureIsLoggedAndDoesNotThrow(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1', 'm-2')]);
        $queue->deleteFailures = ['m-2'];

        $this->runner($queue, new FakeInvoker(self::ok()))->runOnce();

        $errors = array_values(array_filter($this->logs(), static fn(array $entry): bool => $entry['level'] === 'error'));
        self::assertCount(1, $errors);
        self::assertSame('some messages could not be deleted', $errors[0]['message']);
        self::assertSame(['m-2'], $errors[0]['undeletedMessageIds']);
    }

    public function testLogsNeverContainMessageBodies(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1'), self::batch('m-2'), self::batch('m-3')]);
        $invoker = new FakeInvoker(self::ok('{"errorType":"X","errorMessage":"' . self::SECRET_BODY . '"}'));
        $runner = $this->runner($queue, $invoker);

        $runner->runOnce();
        $runner->runOnce();
        $runner->runOnce();

        self::assertNotSame([], $this->logLines);
        self::assertStringNotContainsString(self::SECRET_BODY, implode('', $this->logLines));
    }

    public function testAFailingReceiveIsLoggedThenTheLoopBacksOffAndCarriesOn(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1')]);
        $queue->failReceiveWith = new \RuntimeException('queue unreachable');
        $queue->duringReceive = static function () use ($queue): void {
            // The first poll fails; the retry (second poll) works.
            if ($queue->receiveCalls === 2) {
                $queue->failReceiveWith = null;
            }
        };
        // Stop once the batch of the second, successful iteration has been handled.
        $invoker = new FakeInvoker(self::ok());
        $invoker->duringInvoke = function (): void {
            $this->stop->request();
        };
        $runner = $this->runner($queue, $invoker);

        $runner->run();

        self::assertSame([2], $this->sleeps);
        self::assertSame(['handle-m-1'], $queue->deletedReceiptHandles);
        self::assertContains('iteration failed, retrying', $this->logMessages());
        $failure = array_values(array_filter($this->logs(), static fn(array $entry): bool => $entry['message'] === 'iteration failed, retrying'))[0];
        self::assertSame('queue unreachable', $failure['error']);
        self::assertSame(\RuntimeException::class, $failure['type']);
    }

    public function testAFailingDeleteIsLoggedAndTheLoopGoesOn(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1')]);
        $queue->failDeleteWith = new \RuntimeException('delete refused');
        $this->stop = new StopSignal();
        $sleepStop = $this->stop;
        $runner = new Runner(
            $queue,
            new FakeInvoker(self::ok()),
            new SqsEventBuilder('eu-west-1', 'arn'),
            new BatchResultInterpreter(),
            new JsonLogger(function (string $line): void {
                $this->logLines[] = $line;
            }),
            $sleepStop,
            static function () use ($sleepStop): void {
                $sleepStop->request();
            },
        );

        $runner->run();

        self::assertContains('iteration failed, retrying', $this->logMessages());
        self::assertSame(1, $queue->receiveCalls);
    }

    public function testDoesNotEvenPollWhenStopWasAlreadyRequested(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1')]);
        $this->stop->request();

        $this->runner($queue, new FakeInvoker(self::ok()))->run();

        self::assertSame(0, $queue->receiveCalls);
        self::assertContains('runner stopped', $this->logMessages());
    }

    public function testAStopRequestedWhileABatchIsProcessedStillFinishesThatBatch(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1', 'm-2'), self::batch('m-3')]);
        $invoker = new FakeInvoker(self::ok());
        $invoker->duringInvoke = function (): void {
            $this->stop->request();
        };

        $this->runner($queue, $invoker)->run();

        self::assertSame(['handle-m-1', 'handle-m-2'], $queue->deletedReceiptHandles);
        self::assertSame(1, $queue->receiveCalls, 'no second poll after the stop');
        self::assertSame([], $this->sleeps);
    }

    public function testTheRunnerIsWaitingOnlyWhileItPollsTheQueue(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1')]);
        $seenWhilePolling = null;
        $seenWhileInvoking = null;
        $queue->duringReceive = function () use (&$seenWhilePolling): void {
            $seenWhilePolling ??= $this->stop->isWaiting();
        };
        $invoker = new FakeInvoker(self::ok());
        $invoker->duringInvoke = function () use (&$seenWhileInvoking): void {
            $seenWhileInvoking = $this->stop->isWaiting();
            $this->stop->request();
        };

        $this->runner($queue, $invoker)->runOnce();

        self::assertTrue($seenWhilePolling);
        self::assertFalse($seenWhileInvoking);
        self::assertFalse($this->stop->isWaiting());
    }

    public function testTheWaitingFlagIsClearedWhenThePollThrows(): void
    {
        $queue = new FakeQueueClient([]);
        $queue->failReceiveWith = new \RuntimeException('down');

        try {
            $this->runner($queue, new FakeInvoker(self::ok()))->runOnce();
            self::fail('expected the failure to propagate from runOnce');
        } catch (\RuntimeException) {
            self::assertFalse($this->stop->isWaiting());
        }
    }

    public function testStopFiredJustBeforeTheReceiveCallMakesTheRunnerExitWithoutReceiving(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1')]);
        $this->stop = new StopsWhenWaitingBegins();
        $invoker = new FakeInvoker(self::ok());

        $this->runner($queue, $invoker)->run();

        self::assertSame(0, $queue->receiveCalls, 'the stop arrived after the waiting flag was set: nothing may be received');
        self::assertSame([], $invoker->payloads);
        self::assertFalse($this->stop->isWaiting());
        self::assertContains('runner stopped', $this->logMessages());
    }

    public function testStopFiredDuringAReceiveThatReturnsMessagesStillInvokesAndDeletesTheBatch(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1', 'm-2')]);
        $exitAdvice = null;
        $queue->duringReceive = function () use (&$exitAdvice): void {
            $exitAdvice = $this->stop->request();
        };
        $afterReceiveAdvice = null;
        $invoker = new FakeInvoker(self::ok());
        $invoker->duringInvoke = function () use (&$afterReceiveAdvice): void {
            $afterReceiveAdvice = $this->stop->request();
        };

        $this->runner($queue, $invoker)->run();

        self::assertTrue($exitAdvice, 'while the receive call is pending the handler may exit');
        self::assertFalse($afterReceiveAdvice, 'once the receive call has returned the handler must only set the flag');
        self::assertCount(1, $invoker->payloads);
        self::assertSame(['handle-m-1', 'handle-m-2'], $queue->deletedReceiptHandles);
        self::assertSame(1, $queue->receiveCalls);
    }

    public function testPausesAfterAnInvocationFailedBeforeTheNextPoll(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1'), self::batch('m-2')]);
        $invoker = new FakeInvoker(new InvocationFailed('connection refused'));
        $invoker->duringInvoke = function () use ($invoker): void {
            if (count($invoker->payloads) >= 2) {
                $this->stop->request();
            }
        };

        $this->runner($queue, $invoker)->run();

        self::assertSame([2, 2], $this->sleeps);
    }

    public function testDoesNotPauseAfterAnInvocationThatReturnedAnAnswer(): void
    {
        $queue = new FakeQueueClient([self::batch('m-1')]);

        $this->runner($queue, new FakeInvoker(new InvocationResult(500, 'oops')))->runOnce();

        self::assertSame([], $this->sleeps);
    }
}
