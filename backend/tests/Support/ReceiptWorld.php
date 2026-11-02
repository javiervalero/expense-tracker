<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Session\Principal;
use App\Expenses\ExpenseKeys;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Id\RandomUuidGenerator;
use App\Receipts\Receipt;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptRepository;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Assert;

/**
 * What the end-to-end receipt tests share (ReceiptPipeTest, ReceiptLifecycleTest): the local clients, a seeded pending
 * record, a browser-style form upload to the S3 emulator, bounded polling, prefix listing and the marker files that
 * `make test-integration` reads (under build/pipe-test/, the repository's build/).
 */
final class ReceiptWorld
{
    public const string RECEIPTS_BUCKET = 'receipts';
    public const string THUMBNAILS_BUCKET = 'receipt-thumbnails';
    /** The Host the signed form was made for; the test sends it to the internal endpoint, as a browser reaches the public one. */
    public const string PUBLIC_HOST = 'localhost:9000';
    public const int POLL_SECONDS = 15;
    public const string MARKER_DIRECTORY = 'build/pipe-test';
    private const int POLL_PAUSE_MICROSECONDS = 250_000;
    private const int MINIMUM_WATCH_SECONDS = 4;
    private const int PENDING_TTL_SECONDS = 86_400;

    private function __construct() {}

    public static function s3(): S3Client
    {
        return LocalAws::fromGlobals()->s3();
    }

    public static function receipts(): ReceiptRepository
    {
        return new ReceiptRepository(self::items());
    }

    public static function principal(string $userId): Principal
    {
        return new Principal($userId, str_repeat('a', 64));
    }

    public static function newKey(): ReceiptObjectKey
    {
        $ids = new RandomUuidGenerator();

        return ReceiptObjectKey::from($ids->generate(), $ids->generate(), $ids->generate());
    }

    public static function exists(string $bucket, string $key): bool
    {
        return self::s3()->doesObjectExistV2($bucket, $key);
    }

    public static function receipt(string $userId, string $expenseId): ?Receipt
    {
        return self::receipts()->find(self::principal($userId), $expenseId, new \DateTimeImmutable());
    }

    /**
     * Writes a pending record as the API would have, for a test that has no API session (the worker only reads it).
     */
    public static function seedPending(ReceiptObjectKey $key, string $contentType, int $size): void
    {
        $record = ExpenseKeys::receipt(self::principal($key->userId()), $key->expenseId());
        self::items()->putItem($record + [
            'receiptId' => $key->receiptId(),
            'objectKey' => $key->originalKey(),
            'status' => 'pending',
            'contentType' => $contentType,
            'size' => $size,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'expiresAt' => time() + self::PENDING_TTL_SECONDS,
        ]);
    }

    public static function deleteRecord(ReceiptObjectKey $key): void
    {
        self::items()->deleteItem(ExpenseKeys::receipt(self::principal($key->userId()), $key->expenseId()));
    }

    /**
     * Polls until the probe returns something other than null/false, at most POLL_SECONDS; fails the test otherwise.
     *
     * @template T
     *
     * @param \Closure(): (T|null|false) $probe
     *
     * @return T
     */
    public static function eventually(\Closure $probe, string $what): mixed
    {
        $deadline = microtime(true) + self::POLL_SECONDS;
        do {
            $value = $probe();
            if ($value !== null && $value !== false) {
                return $value;
            }
            usleep(self::POLL_PAUSE_MICROSECONDS);
        } while (microtime(true) < $deadline);
        Assert::fail('not reached within ' . self::POLL_SECONDS . ' s: ' . $what);
    }

    /**
     * POSTs the signed form as a browser would (file part last) to the internal endpoint with the public Host.
     *
     * @param array<string, string> $fields
     *
     * @return int the HTTP status (204 when the object was stored)
     */
    public static function browserUpload(string $policyUrl, array $fields, string $file): int
    {
        $multipart = [];
        foreach ($fields as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }
        $multipart[] = ['name' => 'file', 'contents' => $file, 'filename' => 'receipt'];
        $internal = new Uri(LocalAws::fromGlobals()->s3Endpoint());
        $url = (string) (new Uri($policyUrl))->withScheme($internal->getScheme())->withHost($internal->getHost())->withPort($internal->getPort());
        $http = new HttpClient(['http_errors' => false, 'timeout' => 60, 'allow_redirects' => false]);

        return $http->post($url, ['multipart' => $multipart, 'headers' => ['Host' => self::PUBLIC_HOST]])->getStatusCode();
    }

    /** @return list<string> every key under the prefix */
    public static function keysUnder(string $bucket, string $prefix): array
    {
        $keys = [];
        foreach (self::s3()->getPaginator('ListObjectsV2', ['Bucket' => $bucket, 'Prefix' => $prefix]) as $page) {
            $contents = $page['Contents'] ?? [];
            Assert::assertIsIterable($contents);
            foreach ($contents as $object) {
                Assert::assertIsArray($object);
                Assert::assertIsString($object['Key'] ?? null);
                $keys[] = $object['Key'];
            }
        }

        return $keys;
    }

    public static function deleteEverythingOf(string $userId, string $expenseId): void
    {
        $prefixes = [
            self::RECEIPTS_BUCKET => ReceiptObjectKey::originalPrefix($userId, $expenseId),
            self::THUMBNAILS_BUCKET => ReceiptObjectKey::thumbnailPrefix($userId, $expenseId),
        ];
        foreach ($prefixes as $bucket => $prefix) {
            foreach (self::keysUnder($bucket, $prefix) as $key) {
                self::s3()->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            }
        }
    }

    /** Messages in the receipts dead-letter queue (visible, in flight and delayed). */
    public static function messagesInDeadLetterQueue(): int
    {
        return self::messagesOnQueue('SQS_RECEIPTS_DLQ_URL');
    }

    /** Puts the event S3 would send for the upload of this key (the relay's shape) on the receipts queue. */
    public static function sendS3Event(ReceiptObjectKey $key): void
    {
        $event = ['Records' => [['eventName' => 'ObjectCreated:Post', 's3' => [
            'bucket' => ['name' => self::RECEIPTS_BUCKET],
            'object' => ['key' => rawurlencode($key->originalKey())],
        ]]]];
        self::sqs()->sendMessage(['QueueUrl' => self::queueUrl(), 'MessageBody' => json_encode($event, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Waits until the receipts queue holds nothing (visible, in flight or delayed) after at least MINIMUM_WATCH_SECONDS:
     * an empty queue sooner proves nothing, the event may not have been taken yet.
     */
    public static function waitUntilQueueDrained(): void
    {
        $start = microtime(true);
        do {
            usleep(self::POLL_PAUSE_MICROSECONDS);
            $elapsed = microtime(true) - $start;
            $empty = self::messagesOnQueue() === 0;
        } while (!($empty && $elapsed >= self::MINIMUM_WATCH_SECONDS) && $elapsed < self::POLL_SECONDS);
        Assert::assertTrue($empty, 'the receipts queue was not empty within ' . self::POLL_SECONDS . ' s');
    }

    /** Writes the run-unique value that `make test-integration` looks for in the service logs. */
    public static function writeMarker(string $file, string $value): void
    {
        $directory = dirname(__DIR__, 3) . '/' . self::MARKER_DIRECTORY;
        Assert::assertTrue(is_dir($directory) || mkdir($directory, 0o775, true), 'cannot create ' . $directory);
        Assert::assertNotFalse(file_put_contents($directory . '/' . $file, $value . "\n"), 'cannot write the marker ' . $file);
    }

    private static function sqs(): SqsClient
    {
        return LocalAws::fromGlobals()->sqs();
    }

    private static function queueUrl(string $variable = 'SQS_RECEIPTS_QUEUE_URL'): string
    {
        $url = getenv($variable);
        Assert::assertIsString($url, $variable . ' must be set (see .env.example)');

        return $url;
    }

    private static function messagesOnQueue(string $variable = 'SQS_RECEIPTS_QUEUE_URL'): int
    {
        $names = ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible', 'ApproximateNumberOfMessagesDelayed'];
        $attributes = self::sqs()->getQueueAttributes(['QueueUrl' => self::queueUrl($variable), 'AttributeNames' => $names])->get('Attributes');
        Assert::assertIsArray($attributes);

        return array_sum(array_map(static fn(string $name): int => self::count($attributes[$name] ?? 0), $names));
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function items(): ItemStore
    {
        $table = getenv('DYNAMODB_TABLE');
        Assert::assertIsString($table, 'DYNAMODB_TABLE must be set (see .env.example)');

        return new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $table);
    }
}
