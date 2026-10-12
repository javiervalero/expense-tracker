<?php

declare(strict_types=1);

namespace LocalAws\Runner;

/**
 * The two queue operations the runner needs. {@see SqsQueueClient} is the real one; tests use a fake.
 */
interface QueueClient
{
    /**
     * Long-polls the queue. Blocks up to the wait time; an empty list means nothing arrived.
     *
     * @return list<QueueMessage>
     */
    public function receive(): array;

    /**
     * @param list<QueueMessage> $messages
     *
     * @return list<string> the ids of the messages that could NOT be deleted (empty when all were)
     */
    public function delete(array $messages): array;
}
