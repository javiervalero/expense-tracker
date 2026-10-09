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
    private const VISIBILITY_TIMEOUT_SECONDS = 1;
    private const MESSAGE_WAIT_SECONDS = 10;

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
        $body = '{"Records":[{"key":"receipts/ünï cödé 🧾.jpg"}]}';

        $this->sqs->sendMessage(['QueueUrl' => $this->sourceUrl, 'MessageBody' => $body]);
        $message = $this->receiveOne($this->sourceUrl);

        self::assertNotNull($message, 'the sent message should be received');
        self::assertSame($body, $message['Body']);

        $this->sqs->deleteMessage(['QueueUrl' => $this->sourceUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
        $this->waitForVisibilityToExpire();

        self::assertNull($this->receiveOne($this->sourceUrl), 'a deleted message must not come back');
    }

    public function testReceivingAnEmptyQueueReturnsNoMessages(): void
    {
        self::assertNull($this->receiveOne($this->sourceUrl));
    }

    public function testApproximateReceiveCountIncrementsOnEveryReceive(): void
    {
        $this->sqs->sendMessage(['QueueUrl' => $this->sourceUrl, 'MessageBody' => 'count me']);

        $counts = [];
        for ($receive = 1; $receive <= self::MAX_RECEIVE_COUNT; ++$receive) {
            // The message is never deleted, so it becomes visible again once its visibility timeout ends.
            $message = $this->waitForMessage($this->sourceUrl);
            self::assertNotNull($message, sprintf('receive #%d should return the message', $receive));
            $counts[] = $message['Attributes']['ApproximateReceiveCount'];
        }

        self::assertSame(['1', '2', '3'], $counts);
    }

    public function testMessageLandsInTheDeadLetterQueueAfterTheThirdReceive(): void
    {
        $this->sqs->sendMessage(['QueueUrl' => $this->sourceUrl, 'MessageBody' => 'poison']);

        for ($receive = 1; $receive <= self::MAX_RECEIVE_COUNT; ++$receive) {
            // Never deleted: the consumer "failed" each time, so the message returns after the visibility timeout.
            self::assertNotNull($this->waitForMessage($this->sourceUrl), sprintf('receive #%d', $receive));
        }

        // Like SQS, ElasticMQ moves the message when the next receive finds it over the limit, so polling the
        // source queue is what triggers the move. That poll must never hand the message out a 4th time.
        $deadLettered = $this->pollForDeadLetter();

        self::assertNotNull($deadLettered, 'the message should reach the dead-letter queue');
        self::assertSame('poison', $deadLettered['Body']);
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
     * @return array{Body: string, ReceiptHandle: string, Attributes: array<string, string>}|null
     */
    private function waitForMessage(string $queueUrl): ?array
    {
        $deadline = microtime(true) + self::MESSAGE_WAIT_SECONDS;
        do {
            $message = $this->receiveOne($queueUrl);
            if ($message !== null) {
                return $message;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * @return array{Body: string, ReceiptHandle: string, Attributes: array<string, string>}|null
     */
    private function pollForDeadLetter(): ?array
    {
        $deadline = microtime(true) + self::MESSAGE_WAIT_SECONDS;
        do {
            self::assertNull($this->receiveOne($this->sourceUrl), 'a 4th receive must not return the message');
            $message = $this->receiveOne($this->deadLetterUrl);
            if ($message !== null) {
                return $message;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function waitForVisibilityToExpire(): void
    {
        usleep((self::VISIBILITY_TIMEOUT_SECONDS * 1_000_000) + 300_000);
    }
}
