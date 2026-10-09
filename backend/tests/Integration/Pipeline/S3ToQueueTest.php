<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Tests\Support\LocalAws;
use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Client as HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The first half of the receipt pipe, end to end on the Compose stack: a browser-style presigned POST to the S3
 * emulator -> its webhook -> the `s3-events-relay` service -> one AWS-shaped message on the `receipts` queue
 * (ADR 0006, ADR 0007). Run it through `make test-integration` (the stack must be up).
 *
 * WHO ELSE READS THE QUEUE. These tests read (and delete) the messages of the real `receipts` queue, so nothing
 * else may consume it meanwhile. Today nothing does. From WP11 on the `worker-runner` service does: this suite
 * must then run with that service stopped (`docker compose -p expense-tracker stop worker-runner`, then
 * `start` it again), or the runner would take the messages first and the tests would time out. That is a
 * deliberate choice over a second queue or a Compose profile: the test then checks the very queue and relay
 * configuration the stack uses. The runner's own end-to-end test (ReceiptPipeTest, WP11) covers the runner running.
 *
 * Isolation: every test uses unique keys, starts from a purged queue, deletes the objects it created (deletes emit
 * no event) and purges the queue afterwards.
 */
final class S3ToQueueTest extends TestCase
{
    private const RECEIPTS_BUCKET = 'receipts';
    private const THUMBNAILS_BUCKET = 'receipt-thumbnails';
    private const CONTENT_TYPE = 'image/jpeg';
    private const FIRST_MESSAGE_TIMEOUT_SECONDS = 20;
    /** After the awaited message arrives, keep listening this long for a (wrong) second one. */
    private const SETTLE_SECONDS = 2;
    private const POLL_WAIT_SECONDS = 1;

    private S3Client $s3;
    private SqsClient $sqs;
    private HttpClient $http;
    private string $queueUrl;

    /** @var list<array{bucket: string, key: string}> */
    private array $createdObjects = [];

    protected function setUp(): void
    {
        $localAws = LocalAws::fromGlobals();
        $this->s3 = $localAws->s3();
        $this->sqs = $localAws->sqs();
        $this->http = new HttpClient(['http_errors' => false, 'timeout' => 20]);

        $queueUrl = getenv('SQS_RECEIPTS_QUEUE_URL');
        self::assertIsString($queueUrl, 'SQS_RECEIPTS_QUEUE_URL must be set (see .env.example)');
        $this->queueUrl = $queueUrl;
        $this->sqs->purgeQueue(['QueueUrl' => $this->queueUrl]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdObjects as $object) {
            $this->s3->deleteObject(['Bucket' => $object['bucket'], 'Key' => $object['key']]);
        }
        $this->createdObjects = [];
        $this->sqs->purgeQueue(['QueueUrl' => $this->queueUrl]);
    }

    public function testAnUploadUnderReceiptsProducesExactlyOneAwsShapedMessage(): void
    {
        // A '+' in the key shows that the key travels percent-encoded (and is not decoded or encoded twice).
        $key = $this->newReceiptKey('user+' . bin2hex(random_bytes(4)));

        self::assertSame(204, $this->upload(self::RECEIPTS_BUCKET, $key));
        $messages = $this->messagesUntilSeen($key);

        $forKey = $this->messagesFor($messages, $key);
        self::assertCount(1, $forKey, 'exactly one message for the upload');
        $event = $forKey[0];
        self::assertSame(['Records'], array_keys($event), 'only Records, without the emulator wrapper fields');
        self::assertIsArray($event['Records']);
        self::assertCount(1, $event['Records']);
        self::assertSame(self::RECEIPTS_BUCKET, self::at($event, 'Records', 0, 's3', 'bucket', 'name'));
        $encodedKey = self::encodedKeyOf($event);
        self::assertIsString($encodedKey);
        self::assertStringNotContainsString('+', $encodedKey, 'the "+" of the key is percent-encoded');
        self::assertSame($key, urldecode($encodedKey), 'the worker decodes it exactly once');
        $eventName = self::at($event, 'Records', 0, 'eventName');
        self::assertIsString($eventName);
        self::assertStringStartsWith('ObjectCreated:', str_replace('s3:', '', $eventName));
    }

    public function testUploadsToTheThumbnailsBucketAndOutsideThePrefixProduceNoMessage(): void
    {
        $thumbnail = $this->newReceiptKey('user-' . bin2hex(random_bytes(4)));
        $outsidePrefix = 'conformance/' . bin2hex(random_bytes(8));
        // A control upload that must produce a message: it proves the pipe was working while the others were silent.
        $control = $this->newReceiptKey('user-' . bin2hex(random_bytes(4)));

        self::assertSame(204, $this->upload(self::THUMBNAILS_BUCKET, $thumbnail));
        self::assertSame(204, $this->upload(self::RECEIPTS_BUCKET, $outsidePrefix));
        self::assertSame(204, $this->upload(self::RECEIPTS_BUCKET, $control));
        $messages = $this->messagesUntilSeen($control);

        self::assertCount(1, $this->messagesFor($messages, $control));
        // Not "only one message in the queue": events of other tests (for example the conformance suite's
        // receipts-shaped upload) may still be on their way and are none of this test's business.
        self::assertSame([], $this->messagesFor($messages, $thumbnail), 'the thumbnails bucket has no notification');
        self::assertSame([], $this->messagesFor($messages, $outsidePrefix), 'keys outside receipts/ have no notification');
    }

    private function newReceiptKey(string $user): string
    {
        $id = bin2hex(random_bytes(4));

        return "receipts/{$user}/expense-{$id}/receipt-{$id}";
    }

    /**
     * Presigned POST, built and sent like the front-end will (multipart, the file part last).
     */
    private function upload(string $bucket, string $key): int
    {
        $this->createdObjects[] = ['bucket' => $bucket, 'key' => $key];
        $form = new PostObjectV4(
            $this->s3,
            $bucket,
            ['key' => $key, 'Content-Type' => self::CONTENT_TYPE],
            [
                ['bucket' => $bucket],
                ['eq', '$key', $key],
                ['eq', '$Content-Type', self::CONTENT_TYPE],
                ['content-length-range', 10, 1024],
            ],
            '+10 minutes',
        );
        $action = $form->getFormAttributes()['action'] ?? null;
        self::assertIsString($action);
        $multipart = [];
        foreach ($form->getFormInputs() as $name => $value) {
            $multipart[] = ['name' => (string) $name, 'contents' => $value];
        }
        $multipart[] = ['name' => 'file', 'contents' => str_repeat('x', 100), 'filename' => 'receipt.jpg'];

        return $this->http->post($action, ['multipart' => $multipart])->getStatusCode();
    }

    /**
     * Reads and deletes messages until one concerns $awaitedKey (or the timeout), then for the settle time more.
     *
     * @return list<array<array-key, mixed>> the decoded bodies, in arrival order
     */
    private function messagesUntilSeen(string $awaitedKey): array
    {
        $messages = [];
        $deadline = microtime(true) + self::FIRST_MESSAGE_TIMEOUT_SECONDS;
        $settleUntil = null;
        while (microtime(true) < ($settleUntil ?? $deadline)) {
            foreach ($this->receiveAndDelete() as $body) {
                $messages[] = $body;
            }
            if ($settleUntil === null && $this->messagesFor($messages, $awaitedKey) !== []) {
                $settleUntil = microtime(true) + self::SETTLE_SECONDS;
            }
        }
        self::assertNotNull($settleUntil, 'no message for the uploaded key arrived within ' . self::FIRST_MESSAGE_TIMEOUT_SECONDS . ' s (is worker-runner consuming the queue? Stop it while this suite runs)');

        return $messages;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function receiveAndDelete(): array
    {
        $result = $this->sqs->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => 10,
            'WaitTimeSeconds' => self::POLL_WAIT_SECONDS,
        ]);
        $messages = $result->get('Messages') ?? [];
        self::assertIsArray($messages);
        $bodies = [];
        foreach ($messages as $message) {
            self::assertIsArray($message);
            self::assertIsString($message['ReceiptHandle'] ?? null);
            self::assertIsString($message['Body'] ?? null);
            $this->sqs->deleteMessage(['QueueUrl' => $this->queueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
            $decoded = json_decode($message['Body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $bodies[] = $decoded;
        }

        return $bodies;
    }

    /**
     * @param list<array<array-key, mixed>> $messages
     *
     * @return list<array<array-key, mixed>> the messages whose first record is about $key
     */
    private function messagesFor(array $messages, string $key): array
    {
        return array_values(array_filter($messages, static function (array $event) use ($key): bool {
            $encoded = self::encodedKeyOf($event);

            return $encoded !== null && urldecode($encoded) === $key;
        }));
    }

    /**
     * The key of the first record exactly as the queue message carries it (percent-encoded).
     *
     * @param array<array-key, mixed> $event
     */
    private static function encodedKeyOf(array $event): ?string
    {
        $key = self::at($event, 'Records', 0, 's3', 'object', 'key');

        return is_string($key) ? $key : null;
    }

    /**
     * Follows a path of keys through nested arrays; null when any step is missing.
     *
     * @param array<array-key, mixed> $data
     */
    private static function at(array $data, string|int ...$path): mixed
    {
        $current = $data;
        foreach ($path as $step) {
            if (!is_array($current) || !array_key_exists($step, $current)) {
                return null;
            }
            $current = $current[$step];
        }

        return $current;
    }
}
