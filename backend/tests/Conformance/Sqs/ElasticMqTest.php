<?php

declare(strict_types=1);

namespace App\Tests\Conformance\Sqs;

use App\Tests\Support\LocalAws;
use Aws\Sqs\SqsClient;
use PHPUnit\Framework\TestCase;

/**
 * What the code relies on from SQS, checked against ElasticMQ over the SDK's JSON protocol: send, receive
 * and delete, the receive counter that drives redrive, and the move to the dead-letter queue.
 * Run it through `make test-conformance` (the stack must be up).
 */
final class ElasticMqTest extends TestCase
{
    private const SOURCE_QUEUE = 'conformance-redrive';
    private const DEAD_LETTER_QUEUE = 'conformance-redrive-dlq';
    private const MAX_RECEIVE_COUNT = 3;
    private const MESSAGE_WAIT_SECONDS = 15;
    private const QUIET_WINDOW_SECONDS = 2.5; // more than twice the visibility timeout
    private const POLL_PAUSE_MICROSECONDS = 200_000;

    private SqsClient $sqs;
    private string $sourceUrl;
    private string $deadLetterUrl;

    protected function setUp(): void
    {
        $this->sqs = LocalAws::fromGlobals()->sqs();
        $this->sourceUrl = $this->queueUrl(self::SOURCE_QUEUE);
        $this->deadLetterUrl = $this->queueUrl(self::DEAD_LETTER_QUEUE);

        // The queues are shared by every run: start each test from empty ones.
        $this->sqs->purgeQueue(['QueueUrl' => $this->sourceUrl]);
        $this->sqs->purgeQueue(['QueueUrl' => $this->deadLetterUrl]);
    }

    public function testSendReceiveAndDeleteRoundTrip(): void
    {
        $body = $this->uniqueBody('{"Records":[{"key":"receipts/ünï cödé 🧾.jpg"}]}');

        $this->sqs->sendMessage(['QueueUrl' => $this->sourceUrl, 'MessageBody' => $body]);
        $message = $this->waitForMessage($this->sourceUrl, $body);

        self::assertNotNull($message, 'the sent message should be received');
        self::assertSame($body, $message['Body']);

        $this->sqs->deleteMessage(['QueueUrl' => $this->sourceUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);

        self::assertFalse($this->comesBackWithin($this->sourceUrl, $body, self::QUIET_WINDOW_SECONDS), 'a deleted message must not come back');
    }

    public function testReceivingAnEmptyQueueReturnsNoMessages(): void
    {
        self::assertNull($this->receiveOne($this->sourceUrl));
    }

    /**
     * Timing: the message is never deleted, so it reappears once its visibility timeout (1 s) ends. Each wait polls
     * with a deadline (MESSAGE_WAIT_SECONDS, far above the timeout) instead of sleeping a fixed time. The message
     * is recognised by a body unique to this run, so a message left over from an earlier test or run (a purge does
     * not necessarily remove in-flight messages) can neither be mistaken for it nor hide it.
     */
    public function testApproximateReceiveCountIncrementsOnEveryReceive(): void
    {
        $body = $this->uniqueBody('count me');
        $this->sqs->sendMessage(['QueueUrl' => $this->sourceUrl, 'MessageBody' => $body]);

        $counts = [];
        for ($receive = 1; $receive <= self::MAX_RECEIVE_COUNT; ++$receive) {
            $message = $this->waitForMessage($this->sourceUrl, $body);
            self::assertNotNull($message, sprintf('receive #%d should return the message', $receive));
            $counts[] = $message['Attributes']['ApproximateReceiveCount'];
        }

        self::assertSame(['1', '2', '3'], $counts);
    }

    public function testMessageLandsInTheDeadLetterQueueAfterTheThirdReceive(): void
    {
        $body = $this->uniqueBody('poison');
        $this->sqs->sendMessage(['QueueUrl' => $this->sourceUrl, 'MessageBody' => $body]);

        for ($receive = 1; $receive <= self::MAX_RECEIVE_COUNT; ++$receive) {
            // Never deleted: the consumer "failed" each time, so the message returns after the visibility timeout.
            self::assertNotNull($this->waitForMessage($this->sourceUrl, $body), sprintf('receive #%d', $receive));
        }

        // Like SQS, ElasticMQ moves the message when the next receive finds it over the limit, so polling the
        // source queue is what triggers the move. That poll must never hand the message out a 4th time.
        $deadLettered = $this->pollForDeadLetter($body);

        self::assertNotNull($deadLettered, 'the message should reach the dead-letter queue');
        self::assertSame($body, $deadLettered['Body']);
    }

    /**
     * A body that no other test or earlier run can have used.
     */
    private function uniqueBody(string $prefix): string
    {
        return $prefix . ' #' . bin2hex(random_bytes(6));
    }

    private function queueUrl(string $name): string
    {
        $url = $this->sqs->getQueueUrl(['QueueName' => $name])->get('QueueUrl');
        self::assertIsString($url);

        return $url;
    }

    /**
     * @return array{Body: string, ReceiptHandle: string, Attributes: array<string, string>}|null
     */
    private function receiveOne(string $queueUrl): ?array
    {
        $result = $this->sqs->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => 0,
            'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
        ]);
        $messages = $result->get('Messages');

        /** @var array{Body: string, ReceiptHandle: string, Attributes: array<string, string>}|null */
        return is_array($messages) && $messages !== [] ? $messages[0] : null;
    }

    /**
     * Polls until a message with exactly this body arrives or the deadline passes. Any other message met on the way
     * is deleted: these queues belong to the conformance suite, and a stray one would otherwise keep being handed out.
     *
     * @return array{Body: string, ReceiptHandle: string, Attributes: array<string, string>}|null
     */
    private function waitForMessage(string $queueUrl, string $body): ?array
    {
        $deadline = microtime(true) + self::MESSAGE_WAIT_SECONDS;
        do {
            $message = $this->receiveOne($queueUrl);
            if ($message !== null && $message['Body'] === $body) {
                return $message;
            }
            if ($message !== null) {
                $this->sqs->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
                continue; // the next one may be ours: no pause
            }
            usleep(self::POLL_PAUSE_MICROSECONDS);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * True when a message with this body is received before the window closes (the window outlasts the
     * visibility timeout, so a message that had not been deleted would show up).
     */
    private function comesBackWithin(string $queueUrl, string $body, float $seconds): bool
    {
        $end = microtime(true) + $seconds;
        do {
            $message = $this->receiveOne($queueUrl);
            if ($message !== null && $message['Body'] === $body) {
                return true;
            }
            usleep(self::POLL_PAUSE_MICROSECONDS);
        } while (microtime(true) < $end);

        return false;
    }

    /**
     * @return array{Body: string, ReceiptHandle: string, Attributes: array<string, string>}|null
     */
    private function pollForDeadLetter(string $body): ?array
    {
        $deadline = microtime(true) + self::MESSAGE_WAIT_SECONDS;
        do {
            $fromSource = $this->receiveOne($this->sourceUrl);
            self::assertNotSame($body, $fromSource['Body'] ?? null, 'a 4th receive must not return the message');
            $message = $this->receiveOne($this->deadLetterUrl);
            if ($message !== null && $message['Body'] === $body) {
                return $message;
            }
            usleep(self::POLL_PAUSE_MICROSECONDS);
        } while (microtime(true) < $deadline);

        return null;
    }
}
