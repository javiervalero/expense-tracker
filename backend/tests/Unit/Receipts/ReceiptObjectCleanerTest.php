<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts;

use App\Infrastructure\Clock\Clock;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\ObjectStoreFailure;
use App\Infrastructure\S3\S3ObjectStore;
use App\Infrastructure\S3\S3OriginalObjects;
use App\Infrastructure\S3\S3ThumbnailObjects;
use App\Receipts\ReceiptObjectCleaner;
use App\Receipts\ReceiptObjectKey;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\S3\FailingPrefixStore;
use App\Tests\Support\S3\InMemoryObjectStore;
use App\Tests\Support\S3\RecordingS3;
use App\Tests\Support\S3\TestS3Settings;
use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptObjectCleanerTest extends TestCase
{
    private const USER = '0b3f1c1e-5d2a-4c8e-9a41-2f6d7b8c9d01';
    private const EXPENSE = '1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12';
    private const RECEIPT = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';
    private const LEAKY_TEXT = 'Error executing "DeleteObject" on "https://rcp.s3.amazonaws.com/k?X-Amz-Signature=abc123&Policy=zzz"';

    private InMemoryObjectStore $store;

    /** @var resource */
    private $stream;

    private ReceiptObjectCleaner $cleaner;

    /** @var array<string, string|false> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        foreach (['AWS_ACCESS_KEY_ID' => 'localdev', 'AWS_SECRET_ACCESS_KEY' => 'localdevsecret', 'AWS_EC2_METADATA_DISABLED' => 'true'] as $name => $value) {
            $this->savedEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $this->store = new InMemoryObjectStore();
        $this->cleaner = $this->cleanerOver($this->store);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    private function cleanerOver(\App\Infrastructure\S3\ObjectStore $store, ?Clock $clock = null): ReceiptObjectCleaner
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
        $settings = TestS3Settings::create();

        return new ReceiptObjectCleaner(new S3OriginalObjects($store, $settings), new S3ThumbnailObjects($store, $settings), new JsonLogger($stream), $settings, $clock ?? new FrozenClock());
    }

    /** An ObjectStore whose deletePrefix throws the given failure, recording each call; the other methods do nothing. */
    private static function storeFailingPrefixWith(string $operation, string $code): FailingPrefixStore
    {
        return new FailingPrefixStore(new ObjectStoreFailure($operation, $code));
    }

    private function key(): ReceiptObjectKey
    {
        return ReceiptObjectKey::from(self::USER, self::EXPENSE, self::RECEIPT);
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        $lines = [];
        foreach (array_filter(explode("\n", $this->logged()), static fn(string $line): bool => $line !== '') as $line) {
            $decoded = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $row = [];
            foreach ($decoded as $name => $value) {
                $row[(string) $name] = $value;
            }
            $lines[] = $row;
        }

        return $lines;
    }

    private static function leakyError(string $operation, string $code): S3Exception
    {
        return new S3Exception(self::LEAKY_TEXT, new Command($operation), ['code' => $code, 'request_id' => 'REQ1', 'response' => new \GuzzleHttp\Psr7\Response(403)]);
    }

    private function assertNoLeak(string $text): void
    {
        foreach (['http', 'Signature', 'Policy', 'abc123', 'zzz'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $text);
        }
    }

    public function testRemoveReceiptDeletesTheOriginalAndTheThumbnail(): void
    {
        $this->store->objects['rcp'][$this->key()->originalKey()] = 'o';
        $this->store->objects['thumbs'][$this->key()->thumbnailKey()] = 't';

        $this->cleaner->removeReceipt($this->key());

        self::assertSame([], $this->store->keys('rcp'));
        self::assertSame([], $this->store->keys('thumbs'));
        self::assertSame('', $this->logged());
    }

    public function testRemoveExpenseDeletesBothPrefixes(): void
    {
        $this->cleaner->removeExpense(self::USER, self::EXPENSE);

        self::assertSame(
            ['deletePrefix rcp receipts/' . self::USER . '/' . self::EXPENSE . '/', 'deletePrefix thumbs ' . self::USER . '/' . self::EXPENSE . '/'],
            $this->store->calls,
        );
        self::assertSame('', $this->logged());
    }

    public function testAFailureIsLoggedWithBucketAndKeysAndDoesNotThrow(): void
    {
        $this->store->failing = true;

        $this->cleaner->removeReceipt($this->key());

        $lines = $this->lines();
        self::assertCount(2, $lines, 'the thumbnail is still tried after the original failed');
        self::assertSame('error', $lines[0]['level']);
        self::assertSame('receipt cleanup failed', $lines[0]['message']);
        self::assertSame('rcp', $lines[0]['bucket']);
        self::assertSame([$this->key()->originalKey()], $lines[0]['keys']);
        self::assertSame('thumbs', $lines[1]['bucket']);
        self::assertSame([$this->key()->thumbnailKey()], $lines[1]['keys']);
        self::assertSame('AccessDenied', $lines[0]['errorCode']);
    }

    public function testAnExpenseFailureLogsThePrefixesAndDoesNotThrow(): void
    {
        $this->store->failing = true;

        $this->cleaner->removeExpense(self::USER, self::EXPENSE);

        $lines = $this->lines();
        self::assertCount(2, $lines);
        self::assertSame('receipt cleanup failed', $lines[0]['message']);
        self::assertSame(['receipts/' . self::USER . '/' . self::EXPENSE . '/'], $lines[0]['keys']);
    }

    public function testABadIdIsLoggedNotThrownAfterTheRecordIsGone(): void
    {
        $this->cleaner->removeExpense('not-a-uuid', self::EXPENSE);

        $lines = $this->lines();
        self::assertCount(2, $lines, 'both buckets are attempted and both are reported');
        foreach ($lines as $line) {
            self::assertSame('receipt cleanup failed', $line['message']);
            self::assertSame('error', $line['level']);
            self::assertSame('InvalidId', $line['errorCode']);
            self::assertSame([], $line['keys']);
        }
        self::assertSame('', implode('', $this->store->calls), 'nothing is sent to S3 for a bad id');
        self::assertStringNotContainsString('not-a-uuid', $this->logged(), 'the bad value is not echoed');
    }

    public function testAnEmptyIdIsLoggedNotThrown(): void
    {
        $this->cleaner->removeExpense(self::USER, '');

        self::assertCount(2, $this->lines());
    }

    public function testTheLogLineNeverHoldsAUrlAPolicyOrASignature(): void
    {
        $this->store->failing = true;

        $this->cleaner->removeReceipt($this->key());
        $this->cleaner->removeExpense(self::USER, self::EXPENSE);

        self::assertNotSame('', $this->logged());
        $this->assertNoLeak($this->logged());
    }

    public function testARealSdkDeleteFailureLogsSafeFieldsOnly(): void
    {
        $s3 = new RecordingS3([self::leakyError('DeleteObject', 'AccessDenied'), self::leakyError('DeleteObject', 'AccessDenied')]);
        $cleaner = $this->cleanerOver(new S3ObjectStore($s3->client()));

        $cleaner->removeReceipt($this->key());

        $lines = $this->lines();
        self::assertCount(2, $lines);
        self::assertSame('AccessDenied', $lines[0]['errorCode']);
        self::assertSame(403, $lines[0]['statusCode']);
        self::assertSame('REQ1', $lines[0]['requestId']);
        self::assertSame(S3Exception::class, $lines[0]['exceptionClass']);
        $this->assertNoLeak($this->logged());
    }

    public function testARealSdkListFailureForAnExpenseLogsSafeFieldsOnly(): void
    {
        $s3 = new RecordingS3([self::leakyError('ListObjectsV2', 'AccessDenied'), self::leakyError('ListObjectsV2', 'AccessDenied')]);
        $cleaner = $this->cleanerOver(new S3ObjectStore($s3->client()));

        $cleaner->removeExpense(self::USER, self::EXPENSE);

        self::assertCount(2, $this->lines());
        $this->assertNoLeak($this->logged());
    }

    public function testErrorsInsideA200AreLoggedOnceWithTheirCountAndCodesWithoutTheirText(): void
    {
        $errors = new Result(['Errors' => [
            ['Key' => 'k1', 'Code' => 'AccessDenied', 'Message' => self::LEAKY_TEXT],
            ['Key' => 'k2', 'Code' => 'InternalError', 'Message' => self::LEAKY_TEXT],
        ]]);
        $s3 = new RecordingS3([
            new Result(['Contents' => [['Key' => 'k1'], ['Key' => 'k2']]]),
            $errors,
            new Result([]),
            new Result([]),
        ]);
        $cleaner = $this->cleanerOver(new S3ObjectStore($s3->client()));

        $cleaner->removeExpense(self::USER, self::EXPENSE);

        $lines = $this->lines();
        self::assertCount(1, $lines, 'the thumbnails prefix is empty and succeeds');
        self::assertSame('AccessDenied,InternalError', $lines[0]['errorCode']);
        self::assertSame(2, $lines[0]['errorCount']);
        $this->assertNoLeak($this->logged());
    }

    /** @return array<string, array{string, string}> */
    public static function folderLimitFailures(): array
    {
        return [
            'page limit' => ['ListObjectsV2', 'PageLimitReached'],
            'deadline' => ['ListObjectsV2', 'DeadlineReached'],
            'errors and page limit' => ['DeleteObjects', 'AccessDenied,PageLimitReached'],
        ];
    }

    #[DataProvider('folderLimitFailures')]
    public function testAFolderThatCouldNotBeEmptiedIsLoggedWithItsPrefixAndTheOtherBucketIsStillTried(string $operation, string $code): void
    {
        $store = self::storeFailingPrefixWith($operation, $code);
        $cleaner = $this->cleanerOver($store);

        $cleaner->removeExpense(self::USER, self::EXPENSE);

        $originalPrefix = 'receipts/' . self::USER . '/' . self::EXPENSE . '/';
        $thumbnailPrefix = self::USER . '/' . self::EXPENSE . '/';
        self::assertSame(['rcp ' . $originalPrefix, 'thumbs ' . $thumbnailPrefix], $store->prefixCalls);
        $lines = $this->lines();
        self::assertCount(2, $lines);
        self::assertSame('error', $lines[0]['level']);
        self::assertSame('receipt cleanup failed', $lines[0]['message']);
        self::assertSame($code, $lines[0]['errorCode']);
        self::assertSame($operation, $lines[0]['operation']);
        self::assertSame('rcp', $lines[0]['bucket']);
        self::assertSame([$originalPrefix], $lines[0]['keys']);
        self::assertSame($code, $lines[1]['errorCode']);
        self::assertSame('thumbs', $lines[1]['bucket']);
        self::assertSame([$thumbnailPrefix], $lines[1]['keys']);
    }

    public function testOnceTheBudgetHasPassedTheNextFileIsNotTriedButLogged(): void
    {
        $clock = new FrozenClock();
        $this->store->afterCall = static fn() => $clock->advance(9);
        $cleaner = $this->cleanerOver($this->store, $clock);

        $cleaner->removeReceipt($this->key());

        self::assertSame(['delete rcp ' . $this->key()->originalKey()], $this->store->calls, 'no call is made after the deadline');
        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('error', $lines[0]['level']);
        self::assertSame('receipt cleanup failed', $lines[0]['message']);
        self::assertSame('thumbs', $lines[0]['bucket']);
        self::assertSame([$this->key()->thumbnailKey()], $lines[0]['keys']);
        self::assertSame('DeadlineReached', $lines[0]['errorCode']);
    }

    public function testOnceTheBudgetHasPassedTheSecondFolderIsLoggedAndNotCalled(): void
    {
        $clock = new FrozenClock();
        $this->store->afterCall = static fn() => $clock->advance(9);
        $cleaner = $this->cleanerOver($this->store, $clock);

        $cleaner->removeExpense(self::USER, self::EXPENSE);

        self::assertSame(['deletePrefix rcp receipts/' . self::USER . '/' . self::EXPENSE . '/'], $this->store->calls);
        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('thumbs', $lines[0]['bucket']);
        self::assertSame([self::USER . '/' . self::EXPENSE . '/'], $lines[0]['keys']);
        self::assertSame('DeadlineReached', $lines[0]['errorCode']);
    }

    public function testTheDeadlineIsPassedDownToTheFolderDeletion(): void
    {
        $clock = new FrozenClock();
        $cleaner = $this->cleanerOver($this->store, $clock);

        $cleaner->removeExpense(self::USER, self::EXPENSE);

        $deadline = $this->store->lastDeadline;
        self::assertNotNull($deadline);
        self::assertFalse($deadline->hasPassed());
        $clock->advance(ReceiptObjectCleaner::BUDGET_SECONDS - 1);
        self::assertFalse($deadline->hasPassed());
        $clock->advance(1);
        self::assertTrue($deadline->hasPassed());
    }

    public function testWithinTheBudgetBothFilesAreDeleted(): void
    {
        $clock = new FrozenClock();
        $this->store->afterCall = static fn() => $clock->advance(7);
        $cleaner = $this->cleanerOver($this->store, $clock);

        $cleaner->removeReceipt($this->key());

        self::assertCount(2, $this->store->calls);
        self::assertSame('', $this->logged());
    }

    public function testTheBudgetStartsWhenTheCallStartsNotWhenTheCleanerIsBuilt(): void
    {
        $clock = new FrozenClock();
        $cleaner = $this->cleanerOver($this->store, $clock);
        $clock->advance(3600);

        $cleaner->removeReceipt($this->key());

        self::assertCount(2, $this->store->calls);
        self::assertSame('', $this->logged());
    }
}
