<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use LocalAws\Runner\QueueClient;
use LocalAws\Runner\QueueMessage;

/**
 * Hands out prepared batches (an empty one once they run out) and records what was deleted.
 */
final class FakeQueueClient implements QueueClient
{
    /** @var list<string> receipt handles, in the order they were deleted */
    public array $deletedReceiptHandles = [];
    public int $receiveCalls = 0;
    public int $deleteCalls = 0;
    /** @var list<string> message ids that delete() reports as not deleted */
    public array $deleteFailures = [];
    public ?\Throwable $failReceiveWith = null;
    public ?\Throwable $failDeleteWith = null;
    /** @var (\Closure(): void)|null runs inside receive(), like a signal arriving during the long poll */
    public ?\Closure $duringReceive = null;

    /**
     * @param list<list<QueueMessage>> $batches
     */
    public function __construct(private array $batches = []) {}

    public function receive(): array
    {
        ++$this->receiveCalls;
        if ($this->duringReceive !== null) {
            ($this->duringReceive)();
        }
        if ($this->failReceiveWith !== null) {
            throw $this->failReceiveWith;
        }

        return array_shift($this->batches) ?? [];
    }

    public function delete(array $messages): array
    {
        ++$this->deleteCalls;
        if ($this->failDeleteWith !== null) {
            throw $this->failDeleteWith;
        }
        foreach ($messages as $message) {
            $this->deletedReceiptHandles[] = $message->receiptHandle;
        }

        return $this->deleteFailures;
    }
}
