<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Empties the real `receipts` queue after a test class that created objects under `receipts/` (the bucket rule sends
 * an event for each, through the relay, with a delay). One bounded wait: poll the queue's counts until they are above
 * zero and have not changed for a few polls (the relay has delivered what it will), or until the deadline; then purge
 * once. Only for classes in the group `queue-exclusive`, which `make test-integration` runs with `worker-runner`
 * stopped, so the events are not processed meanwhile.
 */
final class ReceiptQueueDrain
{
    private const int RELAY_DEADLINE_SECONDS = 10;
    private const int POLL_INTERVAL_MICROSECONDS = 500_000;
    private const int STABLE_POLLS = 3;

    private function __construct() {}

    public static function afterEvents(): void
    {
        $sqs = LocalAws::fromGlobals()->sqs();
        $queueUrl = getenv('SQS_RECEIPTS_QUEUE_URL');
        if (!is_string($queueUrl)) {
            return;
        }
        $deadline = microtime(true) + self::RELAY_DEADLINE_SECONDS;
        $last = -1;
        $stable = 0;
        while (microtime(true) < $deadline && $stable < self::STABLE_POLLS) {
            $attributes = $sqs->getQueueAttributes(['QueueUrl' => $queueUrl, 'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible']])->get('Attributes');
            $count = is_array($attributes) ? self::number($attributes['ApproximateNumberOfMessages'] ?? 0) + self::number($attributes['ApproximateNumberOfMessagesNotVisible'] ?? 0) : 0;
            $stable = $count > 0 && $count === $last ? $stable + 1 : 0;
            $last = $count;
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
        $sqs->purgeQueue(['QueueUrl' => $queueUrl]);
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
