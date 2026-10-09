<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Tests\Support\LocalAws;
use App\Tests\Support\PresignedPostUpload;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Client as HttpClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The whole receipt pipe on the Compose stack, runner included: presigned POST -> S3 emulator -> `s3-events-relay` ->
 * `receipts` queue -> `worker-runner` -> `worker` (Bref + RIE) -> messages deleted, or redriven to `receipts-dlq`
 * (ADR 0007, ADR 0012). Run it through `make test-integration` (the stack must be up).
 *
 * The group `needs-runner` is the opposite of S3ToQueueTest's: the `worker-runner` service MUST be running (the
 * Makefile starts it for this part). Messages are consumed by the runner, so these tests cannot read them; they watch
 * the queues' counters instead.
 *
 * What "the worker consumed the upload" rests on: an upload that the worker accepted is deleted, so the queue ends
 * empty and nothing reaches the DLQ. An empty queue alone would also be true if no event had ever arrived, and this
 * test has no way to read the other containers' logs (no Docker socket). So each test leaves a marker file under
 * `build/pipe-test/` (the repository's build/, mounted at /app/build) and `make test-integration` greps the logs for
 * exactly that run-unique value:
 *  - `upload-key.txt`: the worker's log must have a "receipt received" line with that exact (decoded) key;
 *  - `poison-message-id.txt`: the runner's log must have a "messages failed, left on the queue" line naming that message
 *    id (the worker returned it in batchItemFailures), so the DLQ test proves a rejection by the worker and not just
 *    an outage of the runner or the worker.
 * These tests are therefore only COMPLETE under `make test-integration`: run on their own, they check the queue
 * counters and the DLQ, but not the logs. The delivery itself (upload -> message) is also proven by S3ToQueueTest.
 */
#[Group('needs-runner')]
final class ReceiptPipeTest extends TestCase
{
    private const BUCKET = 'receipts';
    /** The definition of done says "within about 10 s"; the test allows some slack on a busy CI machine. */
    private const CONSUMED_WITHIN_SECONDS = 15;
    /** An empty queue before this many seconds proves nothing: the event may not have arrived yet. */
    private const MINIMUM_WATCH_SECONDS = 4;
    /** 3 receives x the 10 s visibility timeout, and the move to the DLQ happens at the next receive attempt. */
    private const DEAD_LETTER_WITHIN_SECONDS = 60;
    private const MIN_SECONDS_BEFORE_DEAD_LETTER = 20;
    private const POLL_PAUSE_MICROSECONDS = 250_000;
    /** Read by the Makefile (`make test-integration`); keep the names in sync with it. */
    private const MARKER_DIRECTORY = 'build/pipe-test';
    private const UPLOAD_KEY_MARKER = 'upload-key.txt';
    private const POISON_ID_MARKER = 'poison-message-id.txt';

    private S3Client $s3;
    private SqsClient $sqs;
    private HttpClient $http;
    private string $queueUrl;
    private string $deadLetterQueueUrl;
    /** @var list<string> */
    private array $createdKeys = [];

    protected function setUp(): void
    {
        $localAws = LocalAws::fromGlobals();
        $this->s3 = $localAws->s3();
        $this->sqs = $localAws->sqs();
        $this->http = new HttpClient(['http_errors' => false, 'timeout' => 20]);
        $this->queueUrl = self::requiredEnvironment('SQS_RECEIPTS_QUEUE_URL');
        $this->deadLetterQueueUrl = self::requiredEnvironment('SQS_RECEIPTS_DLQ_URL');
        $this->purgeBoth();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdKeys as $key) {
            $this->s3->deleteObject(['Bucket' => self::BUCKET, 'Key' => $key]);
        }
        $this->createdKeys = [];
        $this->purgeBoth();
    }

    public function testAnUploadUnderReceiptsIsConsumedAndNothingReachesTheDeadLetterQueue(): void
    {
        $id = bin2hex(random_bytes(4));
        // The prefix `pipe+` is what `make test-integration` looks for in the worker's log; the '+' travels percent-encoded.
        $key = "receipts/pipe+{$id}/expense-{$id}/receipt-{$id}";
        $this->createdKeys[] = $key;
        self::writeMarker(self::UPLOAD_KEY_MARKER, $key);

        self::assertSame(204, PresignedPostUpload::send($this->s3, $this->http, self::BUCKET, $key));
        $seconds = $this->secondsUntilDrained($this->queueUrl);

        self::assertLessThanOrEqual(self::CONSUMED_WITHIN_SECONDS, $seconds, 'the receipts queue was not empty within the time limit');
        self::assertSame(0, $this->messagesIn($this->deadLetterQueueUrl), 'a message the worker accepts must not reach the DLQ');
        fwrite(STDERR, sprintf("\n[ReceiptPipeTest] upload consumed in %.1f s\n", $seconds));
    }

    #[Group('slow')]
    public function testAMessageTheWorkerRejectsIsRedrivenAndEndsInTheDeadLetterQueueAfterThreeReceives(): void
    {
        $poison = 'poison ' . bin2hex(random_bytes(8)) . ' - not an S3 event';
        $sent = $this->sqs->sendMessage(['QueueUrl' => $this->queueUrl, 'MessageBody' => $poison]);
        $messageId = $sent->get('MessageId');
        self::assertIsString($messageId);
        self::writeMarker(self::POISON_ID_MARKER, $messageId);

        $start = microtime(true);
        $deadLettered = null;
        while ($deadLettered === null && microtime(true) - $start < self::DEAD_LETTER_WITHIN_SECONDS) {
            $deadLettered = $this->takeFromDeadLetterQueue($poison);
        }
        $seconds = microtime(true) - $start;

        self::assertNotNull($deadLettered, 'the rejected message did not reach receipts-dlq within ' . self::DEAD_LETTER_WITHIN_SECONDS . ' s');
        self::assertGreaterThan(self::MIN_SECONDS_BEFORE_DEAD_LETTER, $seconds, 'it must be received 3 times (10 s visibility each) before it is moved');
        self::assertSame(0, $this->messagesIn($this->queueUrl), 'nothing is left on the receipts queue');
        fwrite(STDERR, sprintf("\n[ReceiptPipeTest] poison message in the DLQ after %.1f s\n", $seconds));
    }

    /**
     * Polls the counters of a queue until it holds nothing at all (visible, in flight or delayed), but not before the
     * minimum watch time (see MINIMUM_WATCH_SECONDS), and at most the time limit plus a second.
     *
     * @return float the seconds it took
     */
    private function secondsUntilDrained(string $queueUrl): float
    {
        $start = microtime(true);
        while (true) {
            $elapsed = microtime(true) - $start;
            $empty = $this->messagesIn($queueUrl) === 0;
            if ($elapsed >= self::MINIMUM_WATCH_SECONDS && $empty) {
                return $elapsed;
            }
            if ($elapsed > self::CONSUMED_WITHIN_SECONDS) {
                return $elapsed;
            }
            usleep(self::POLL_PAUSE_MICROSECONDS);
        }
    }

    /**
     * Receives from the DLQ for a moment; returns the body when it is the awaited one (and deletes it), else null.
     */
    private function takeFromDeadLetterQueue(string $awaitedBody): ?string
    {
        $messages = $this->sqs->receiveMessage([
            'QueueUrl' => $this->deadLetterQueueUrl,
            'MaxNumberOfMessages' => 10,
            'WaitTimeSeconds' => 2,
        ])->get('Messages') ?? [];
        self::assertIsArray($messages);
        $found = null;
        foreach ($messages as $message) {
            self::assertIsArray($message);
            self::assertIsString($message['ReceiptHandle'] ?? null);
            $this->sqs->deleteMessage(['QueueUrl' => $this->deadLetterQueueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
            if (($message['Body'] ?? null) === $awaitedBody) {
                $found = $awaitedBody;
            }
        }

        return $found;
    }

    private function messagesIn(string $queueUrl): int
    {
        $attributes = $this->sqs->getQueueAttributes([
            'QueueUrl' => $queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible', 'ApproximateNumberOfMessagesDelayed'],
        ])->get('Attributes');
        self::assertIsArray($attributes);

        $total = 0;
        foreach (['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible', 'ApproximateNumberOfMessagesDelayed'] as $name) {
            $count = $attributes[$name] ?? '0';
            self::assertIsString($count);
            $total += (int) $count;
        }

        return $total;
    }

    private function purgeBoth(): void
    {
        $this->sqs->purgeQueue(['QueueUrl' => $this->queueUrl]);
        $this->sqs->purgeQueue(['QueueUrl' => $this->deadLetterQueueUrl]);
    }

    /**
     * Writes the run-unique value that the Makefile looks for in the service logs (see the class comment).
     */
    private static function writeMarker(string $file, string $value): void
    {
        $directory = dirname(__DIR__, 4) . '/' . self::MARKER_DIRECTORY;
        self::assertTrue(is_dir($directory) || mkdir($directory, 0o775, true), 'cannot create ' . $directory);
        self::assertNotFalse(file_put_contents($directory . '/' . $file, $value . "\n"), 'cannot write the marker ' . $file);
    }

    private static function requiredEnvironment(string $name): string
    {
        $value = getenv($name);
        self::assertIsString($value, $name . ' must be set (see .env.example)');

        return $value;
    }
}
