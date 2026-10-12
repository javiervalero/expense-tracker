<?php

declare(strict_types=1);

namespace LocalAws\Runner;

use LocalAws\Logging\JsonLogger;

/**
 * Stands in for the Lambda SQS event source mapping, locally: polls the queue, hands each batch to the worker as an
 * AWS-shaped SQS event, and deletes only the messages that succeeded. Everything else stays on the queue, becomes
 * visible again after the visibility timeout and, after the queue's maxReceiveCount, goes to the dead-letter queue.
 *
 * Logs one JSON line per event: batch size, message ids, failed ids. Never message bodies.
 */
final readonly class Runner
{
    private const ERROR_PAUSE_SECONDS = 2;

    /**
     * @param \Closure(int): void $sleep pauses for that many seconds (injected so tests do not wait)
     */
    public function __construct(
        private QueueClient $queue,
        private LambdaInvoker $invoker,
        private SqsEventBuilder $eventBuilder,
        private BatchResultInterpreter $interpreter,
        private JsonLogger $logger,
        private StopSignal $stop,
        private \Closure $sleep,
    ) {}

    /**
     * Runs until a stop is requested. The batch in hand is always finished first; a failure of one iteration is
     * logged and retried after a short pause, so a queue that is down for a while does not end the process.
     */
    public function run(): void
    {
        while (!$this->stop->isRequested()) {
            try {
                $this->runOnce();
            } catch (\Throwable $failure) {
                $this->logger->error('iteration failed, retrying', ['error' => $failure->getMessage(), 'type' => $failure::class]);
                ($this->sleep)(self::ERROR_PAUSE_SECONDS);
            }
        }
        $this->logger->info('runner stopped');
    }

    /**
     * One long poll and, when it returned messages, one invocation.
     *
     * @throws \Throwable when the queue cannot be polled or deleted from
     */
    public function runOnce(): void
    {
        $messages = $this->poll();
        if ($messages === []) {
            return;
        }
        $messageIds = array_map(static fn(QueueMessage $message): string => $message->messageId, $messages);
        $this->logger->info('batch received', ['size' => count($messages), 'messageIds' => $messageIds]);

        $verdict = $this->invoke($messages, $messageIds);
        if ($verdict === null) {
            return;
        }
        if ($verdict->wholeBatchFailed) {
            $this->logger->error('batch failed, nothing deleted', ['size' => count($messages), 'messageIds' => $messageIds, 'reason' => $verdict->reason]);

            return;
        }
        $this->deleteSucceeded($messages, $verdict->failedMessageIds);
    }

    /**
     * @return list<QueueMessage>
     */
    private function poll(): array
    {
        // While waiting, a stop signal may end the process at once (see StopSignal); never while a batch is handled.
        $this->stop->setWaiting(true);
        try {
            // A stop that arrived before the flag was set (or in the instant after) must not start a long poll; a
            // handler that saw "waiting" would otherwise have exited, a handler that did not would be ignored here.
            if ($this->stop->isRequested()) {
                return [];
            }
            $messages = $this->queue->receive();
        } finally {
            // Cleared the moment receive() has returned (or thrown): from here on the signal handler only sets the
            // flag, so messages that were received are always handled and deleted before the process ends.
            $this->stop->setWaiting(false);
        }

        return $messages;
    }

    /**
     * @param list<QueueMessage> $messages
     * @param list<string> $messageIds
     *
     * @return BatchVerdict|null null when the function could not be invoked at all (already logged)
     */
    private function invoke(array $messages, array $messageIds): ?BatchVerdict
    {
        try {
            $result = $this->invoker->invoke($this->eventBuilder->build($messages));
        } catch (InvocationFailed $failure) {
            $this->logger->error('invocation failed, nothing deleted', ['size' => count($messages), 'messageIds' => $messageIds, 'error' => $failure->getMessage()]);
            // The function endpoint is unreachable: do not poll again (and burn a receive of every message) at once.
            ($this->sleep)(self::ERROR_PAUSE_SECONDS);

            return null;
        }

        return $this->interpreter->interpret($messageIds, $result);
    }

    /**
     * @param list<QueueMessage> $messages
     * @param list<string> $failedMessageIds
     */
    private function deleteSucceeded(array $messages, array $failedMessageIds): void
    {
        $succeeded = array_values(array_filter(
            $messages,
            static fn(QueueMessage $message): bool => !in_array($message->messageId, $failedMessageIds, true),
        ));
        if ($failedMessageIds !== []) {
            $this->logger->warning('messages failed, left on the queue', ['failedMessageIds' => $failedMessageIds]);
        }
        $undeleted = $succeeded === [] ? [] : $this->queue->delete($succeeded);
        if ($undeleted !== []) {
            $this->logger->error('some messages could not be deleted', ['undeletedMessageIds' => $undeleted]);
        }
        $this->logger->info('batch processed', ['size' => count($messages), 'deleted' => count($succeeded) - count($undeleted), 'failed' => count($failedMessageIds)]);
    }
}
