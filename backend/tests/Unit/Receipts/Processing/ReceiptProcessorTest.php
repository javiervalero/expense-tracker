<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts\Processing;

use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\ObjectStoreFailure;
use App\Infrastructure\S3\S3OriginalObjects;
use App\Infrastructure\S3\S3Settings;
use App\Infrastructure\S3\S3ThumbnailObjects;
use App\Queue\SqsBatchProcessor;
use App\Receipts\InvalidS3Event;
use App\Receipts\Processing\FileInspector;
use App\Receipts\Processing\ReceiptProcessor;
use App\Receipts\Processing\ThumbnailMaker;
use App\Receipts\Processing\ThumbnailRenderer;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptObjectMissing;
use App\Receipts\ReceiptRepository;
use App\Receipts\S3EventParser;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\Receipts\ReceiptFixtures;
use App\Tests\Support\S3\InMemoryObjectStore;
use App\Tests\Support\StoredReceipt;
use Aws\Command;
use Aws\CommandInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use ErrorException;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * C10, step by step. DynamoDB answers are scripted in the order the processor asks; S3 is in memory; one shared event
 * list shows the ORDER of calls across both.
 */
final class ReceiptProcessorTest extends TestCase
{
    private const string RECEIPTS = 'receipts';
    private const string THUMBNAILS = 'receipt-thumbnails';
    private const string OTHER_RECEIPT = 'a1b2c3d4-e5f6-4789-8abc-def012345678';

    private MockHandler $mock;
    private InMemoryObjectStore $store;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $events;

    /** @var list<array<mixed>> */
    private array $commands = [];

    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $this->events = new \ArrayObject();
        $this->commands = [];
        $this->store = new InMemoryObjectStore();
        $this->store->events = $this->events;
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->log = $stream;
    }

    private function processor(?ThumbnailRenderer $renderer = null): ReceiptProcessor
    {
        $dynamo = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command->toArray();
            $this->events->append('dynamodb ' . $command->getName());
            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $s3 = S3Settings::fromEnvironment(['RECEIPTS_BUCKET' => self::RECEIPTS, 'THUMBNAILS_BUCKET' => self::THUMBNAILS, 'AWS_REGION' => 'eu-west-1']);

        return new ReceiptProcessor(
            new S3EventParser(),
            new ReceiptRepository(new ItemStore(DynamoDbClientFactory::create($dynamo, $recording), $dynamo->tableName)),
            new S3OriginalObjects($this->store, $s3),
            new S3ThumbnailObjects($this->store, $s3),
            new FileInspector(),
            $renderer ?? new ThumbnailMaker(),
            new JsonLogger($this->log),
            new FrozenClock(),
            $s3,
        );
    }

    /**
     * @return array{messageId: string, body: string}
     */
    private static function record(string $bucket, string $decodedKey, string $messageId = 'm1'): array
    {
        $event = ['Records' => [['s3' => ['bucket' => ['name' => $bucket], 'object' => ['key' => urlencode($decodedKey)]]]]];

        return ['messageId' => $messageId, 'body' => json_encode($event, JSON_THROW_ON_ERROR)];
    }

    /**
     * @return array{messageId: string, body: string}
     */
    private static function ourRecord(): array
    {
        return self::record(self::RECEIPTS, StoredReceipt::key()->originalKey());
    }

    private function upload(string $bytes): void
    {
        $this->store->objects[self::RECEIPTS][StoredReceipt::key()->originalKey()] = $bytes;
    }

    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    /**
     * @return list<string> the calls made so far, in order ("dynamodb GetItem", "s3 put ...")
     */
    private function events(): array
    {
        return array_values($this->events->getArrayCopy());
    }

    /**
     * @return mixed the marshalled value (`['S' => ...]`) a placeholder had in a DynamoDB request (by default the second
     *               call, the first UpdateItem)
     */
    private function updated(string $placeholder, int $command = 1): mixed
    {
        $values = $this->commands[$command]['ExpressionAttributeValues'] ?? null;

        return is_array($values) ? ($values[$placeholder] ?? null) : null;
    }

    private static function conditionFailed(): DynamoDbException
    {
        return new DynamoDbException('failed', new Command('UpdateItem'), ['code' => 'ConditionalCheckFailedException']);
    }

    private static function denied(string $operation): DynamoDbException
    {
        return new DynamoDbException('denied', new Command($operation), ['code' => 'AccessDeniedException']);
    }

    private static function original(): string
    {
        return 's3 delete ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey();
    }

    private static function thumbnail(string $operation): string
    {
        return 's3 ' . $operation . ' ' . self::THUMBNAILS . ' ' . StoredReceipt::key()->thumbnailKey();
    }

    // ---- the happy paths ----

    public function testAValidImageGetsItsThumbnailAndThenBecomesReady(): void
    {
        $this->upload(ReceiptFixtures::png(40, 30));
        $this->mock->append(StoredReceipt::found(), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame([
            'dynamodb GetItem',
            's3 get ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey(),
            self::thumbnail('put'),
            'dynamodb UpdateItem',
        ], $this->events());
        self::assertSame(['S' => 'ready'], $this->updated(':ready'));
        self::assertSame(['S' => 'image/png'], $this->updated(':detected'));
        self::assertSame(['S' => InMemoryObjectStore::etagOf(ReceiptFixtures::png(40, 30))], $this->updated(':etag'), 'the ETag of the inspected bytes is kept');
        $jpeg = $this->store->objects[self::THUMBNAILS][StoredReceipt::key()->thumbnailKey()] ?? '';
        self::assertStringStartsWith("\xFF\xD8\xFF", $jpeg);
        $logged = $this->logged();
        self::assertStringContainsString('"message":"receipt ready"', $logged);
        self::assertStringContainsString('"key":"' . StoredReceipt::key()->originalKey() . '"', $logged);
        self::assertStringContainsString('"messageId":"m1"', $logged);
    }

    public function testAPdfBecomesReadyWithoutAThumbnail(): void
    {
        $this->upload(ReceiptFixtures::pdf());
        $this->mock->append(StoredReceipt::found('pending', 'application/pdf'), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem', 's3 get ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey(), 'dynamodb UpdateItem'], $this->events());
        self::assertSame(['S' => 'application/pdf'], $this->updated(':detected'));
        self::assertSame([], $this->store->keys(self::THUMBNAILS));
    }

    /** Amended in the WP8 review: the record says why first, then the original goes. */
    public function testAnInvalidFileFailsTheRecordAndThenDeletesTheOriginalWithoutAnError(): void
    {
        $html = '<html><script>alert(1)</script></html>';
        $this->upload($html);
        $this->mock->append(StoredReceipt::found(), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame([
            'dynamodb GetItem',
            's3 get ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey(),
            'dynamodb UpdateItem',
            self::original(),
        ], $this->events());
        self::assertSame(['S' => 'failed'], $this->updated(':failed'));
        self::assertSame(['S' => 'unsupported-type'], $this->updated(':reason'));
        self::assertSame(['S' => InMemoryObjectStore::etagOf($html)], $this->updated(':etag'));
        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertStringContainsString('"message":"receipt failed"', $this->logged());
        self::assertStringContainsString('"failureReason":"unsupported-type"', $this->logged());
    }

    public function testAStorageErrorWhileFailingTheRecordLeavesTheOriginalAndTheRetryEndsFailed(): void
    {
        $this->upload('<svg/>');
        $this->mock->append(StoredReceipt::found(), self::denied('UpdateItem'));

        try {
            $this->processor()->process(self::ourRecord());
            self::fail('the record must be retried');
        } catch (StorageFailure) {
            self::assertSame([StoredReceipt::key()->originalKey()], $this->store->keys(self::RECEIPTS), 'the original is still there');
        }

        $this->mock->append(StoredReceipt::found(), new Result([]));
        $this->processor()->process(self::ourRecord());

        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertStringContainsString('"message":"receipt failed"', $this->logged());
    }

    public function testADeleteErrorAfterTheRecordFailedIsRetriedAndTheRetryDeletesTheOrphanWithoutWriting(): void
    {
        $this->upload('<svg/>');
        $this->mock->append(StoredReceipt::found(), new Result([]));
        $this->store->failOperation = 'delete';

        try {
            $this->processor()->process(self::ourRecord());
            self::fail('the record must be retried');
        } catch (ObjectStoreFailure) {
            self::assertCount(1, $this->store->keys(self::RECEIPTS));
        }

        $this->store->failOperation = null;
        $before = count($this->commands);
        $this->mock->append(StoredReceipt::found('failed', 'image/png', '2026-10-23T11:00:00+00:00', 'unsupported-type', '2026-10-16T11:01:00Z'));
        $this->processor()->process(self::ourRecord());

        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertCount($before + 1, $this->commands, 'only the GetItem: nothing was written');
    }

    // ---- a thumbnail that cannot be made ----

    /**
     * @return array<string, array{\Throwable, string}>
     */
    public static function thumbnailFailures(): array
    {
        return [
            'runtime exception' => [new \RuntimeException('secret detail: /private/receipt.png'), 'RuntimeException'],
            'error exception from the error handler' => [new ErrorException('secret detail from GD', 0, E_WARNING), 'ErrorException'],
        ];
    }

    #[DataProvider('thumbnailFailures')]
    public function testAThumbnailThatCannotBeMadeAfterAnAcceptedInspectionFailsTheReceiptAsUnreadable(\Throwable $failure, string $class): void
    {
        $this->upload(ReceiptFixtures::png(40, 30));
        $this->mock->append(StoredReceipt::found(), new Result([]));
        $renderer = new class ($failure) implements ThumbnailRenderer {
            public function __construct(private \Throwable $failure) {}

            public function make(string $bytes): string
            {
                throw $this->failure;
            }
        };

        $this->processor($renderer)->process(self::ourRecord());

        self::assertSame(['S' => 'unreadable'], $this->updated(':reason'));
        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertSame([], $this->store->keys(self::THUMBNAILS));
        $logged = $this->logged();
        self::assertStringContainsString('"message":"thumbnail failed"', $logged);
        self::assertStringContainsString('"exceptionClass":"' . $class . '"', $logged);
        self::assertStringNotContainsString('secret detail', $logged, 'only the class of the exception is logged');
        self::assertStringContainsString('"message":"receipt failed"', $logged);
    }


    /**
     * ThumbnailMaker throws on a file GD cannot decode, so a refused file reaching it would be an error (a retry),
     * not a failed receipt. Only an accepted inspection may reach it.
     */
    public function testNeverMakesAThumbnailOfAFileTheInspectorRefused(): void
    {
        $this->upload(substr(ReceiptFixtures::png(40, 30), 0, 60));
        $this->mock->append(StoredReceipt::found(), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame([], $this->store->keys(self::THUMBNAILS));
        self::assertSame(['S' => 'unreadable'], $this->updated(':reason'));
    }

    public function testAnObjectOverTheSizeLimitFailsAsTooLargeInsteadOfRetrying(): void
    {
        $this->upload(str_repeat('a', ReceiptLimits::MAX_BYTES + 1));
        $this->mock->append(StoredReceipt::found(), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame(['S' => 'too-large'], $this->updated(':reason'));
        self::assertSame([], $this->store->keys(self::RECEIPTS));
    }

    // ---- step 1: what is not ours ----

    public function testATestEventDoesNothing(): void
    {
        $record = ['messageId' => 'm1', 'body' => '{"Service":"Amazon S3","Event":"s3:TestEvent"}'];

        $this->processor()->process($record);

        self::assertSame([], $this->events());
        self::assertStringContainsString('"message":"s3 event without objects ignored"', $this->logged());
    }

    public function testAForeignBucketIsLoggedAndSkipped(): void
    {
        $this->processor()->process(self::record('somewhere-else', StoredReceipt::key()->originalKey()));

        self::assertSame([], $this->events());
        $logged = $this->logged();
        self::assertStringContainsString('"message":"receipt event skipped"', $logged);
        self::assertStringContainsString('"bucket":"somewhere-else"', $logged);
        self::assertStringContainsString('"key":"' . StoredReceipt::key()->originalKey() . '"', $logged);
    }

    public function testAKeyThatDoesNotParseIsLoggedWithItsDecodedFormAndSkipped(): void
    {
        $this->processor()->process(self::record(self::RECEIPTS, 'receipts/user-1/expense-1/receipt-1'));

        self::assertSame([], $this->events());
        $logged = $this->logged();
        self::assertStringContainsString('"message":"receipt event skipped"', $logged);
        self::assertStringContainsString('"key":"receipts/user-1/expense-1/receipt-1"', $logged);
    }

    public function testTheEmulatorFixtureIsSkippedWithItsDecodedKey(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/s3-events/silo-object-created-post.json');

        $this->processor()->process(['messageId' => 'm1', 'body' => $body]);

        self::assertSame([], $this->events());
        self::assertStringContainsString('"key":"receipts/user-fe70a955/expense-fe70a955/receipt-fe70a955"', $this->logged());
    }

    public function testAnAwsShapedEventForAParseableKeyIsProcessed(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/s3-events/aws-object-created-post.json');
        $body = str_replace(['"amzn-s3-demo-bucket"', '"HappyFace.jpg"'], ['"' . self::RECEIPTS . '"', '"' . urlencode(StoredReceipt::key()->originalKey()) . '"'], $body);
        self::assertStringContainsString(urlencode(StoredReceipt::key()->originalKey()), $body, 'the fixture changed');
        $this->upload(ReceiptFixtures::pdf());
        $this->mock->append(StoredReceipt::found('pending', 'application/pdf'), new Result([]));

        $this->processor()->process(['messageId' => 'm1', 'body' => $body]);

        self::assertContains('dynamodb UpdateItem', $this->events());
    }

    /** From the WP2 reviews: the key is decoded once; a `%2F` left inside a segment must never become a slash. */
    public function testAnEncodedSlashInsideASegmentIsSkippedNotNormalised(): void
    {
        $sneaky = 'receipts/' . StoredReceipt::USER . '/' . StoredReceipt::EXPENSE . '%2F' . StoredReceipt::RECEIPT;
        $this->upload('should never be read');

        $this->processor()->process(self::record(self::RECEIPTS, $sneaky));

        self::assertSame([], $this->events());
        self::assertStringContainsString('"message":"receipt event skipped"', $this->logged());
        self::assertStringContainsString('%2F', $this->logged());
    }

    public function testABodyThatIsNotAnS3EventIsAnError(): void
    {
        $this->expectException(InvalidS3Event::class);

        $this->processor()->process(['messageId' => 'm1', 'body' => 'not json']);
    }

    public function testARecordWithoutAStringBodyIsAnError(): void
    {
        $this->expectException(InvalidS3Event::class);

        $this->processor()->process(['messageId' => 'm1']);
    }

    // ---- step 2 and 7: the record is not a pending one of ours ----

    public function testADuplicateAfterReadyWritesNothing(): void
    {
        $this->mock->append(StoredReceipt::ready());

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem'], $this->events());
        self::assertStringContainsString('"message":"receipt already processed"', $this->logged());
    }

    /** Amended in the WP8 review: bytes re-POSTed to a failed receipt's key are removed (idempotent deletes), nothing is written. */
    public function testADuplicateAfterFailedDeletesWhateverIsAtTheKeyAndWritesNothing(): void
    {
        $this->upload('<svg/>');
        $this->mock->append(StoredReceipt::found('failed', 'image/png', '2026-10-23T11:00:00+00:00', 'unreadable', '2026-10-16T11:01:00Z'));

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem', self::original(), self::thumbnail('delete')], $this->events());
        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertStringContainsString('"message":"receipt already processed"', $this->logged());
    }

    // ---- F1: the same upload policy can write again within its 5 minutes ----

    /**
     * @return Result<string, mixed> a GetItem answer: a ready record holding the ETag of the bytes it inspected
     */
    private static function readyWithEtagOf(string $inspected): Result
    {
        return StoredReceipt::ready('image/png', InMemoryObjectStore::etagOf($inspected));
    }

    public function testADuplicateEventForTheSameBytesWritesNothing(): void
    {
        $png = ReceiptFixtures::png(40, 30);
        $this->upload($png);
        $this->store->objects[self::THUMBNAILS][StoredReceipt::key()->thumbnailKey()] = 'jpeg';
        $this->mock->append(self::readyWithEtagOf($png));

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem', 's3 etag ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey()], $this->events());
        self::assertCount(1, $this->store->keys(self::RECEIPTS));
        self::assertCount(1, $this->store->keys(self::THUMBNAILS));
        self::assertStringContainsString('"message":"receipt already processed"', $this->logged());
    }

    public function testADuplicateEventForAnObjectThatIsGoneDoesNothing(): void
    {
        $this->mock->append(self::readyWithEtagOf('whatever was inspected'));

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem', 's3 etag ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey()], $this->events());
        self::assertStringContainsString('"message":"receipt already processed"', $this->logged());
    }

    public function testAReadyRecordFromBeforeEtagsWasKeptIsNotCheckedAndNotRead(): void
    {
        $this->upload('other bytes');
        $this->mock->append(StoredReceipt::ready());

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem'], $this->events());
    }

    public function testDifferentBytesAtTheKeyOfAReadyReceiptFailTheRecordAndThenDeleteBothFiles(): void
    {
        $inspected = ReceiptFixtures::png(40, 30);
        $this->upload('<html>written later with the same policy</html>');
        $this->store->objects[self::THUMBNAILS][StoredReceipt::key()->thumbnailKey()] = 'jpeg';
        $this->mock->append(self::readyWithEtagOf($inspected), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame([
            'dynamodb GetItem',
            's3 etag ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey(),
            'dynamodb UpdateItem',
            self::original(),
            self::thumbnail('delete'),
        ], $this->events());
        self::assertSame(['S' => 'failed'], $this->updated(':failed'));
        self::assertSame(['S' => 'unreadable'], $this->updated(':reason'));
        self::assertSame(['S' => 'ready'], $this->updated(':ready'));
        self::assertSame(['S' => InMemoryObjectStore::etagOf($inspected)], $this->updated(':stored'));
        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertSame([], $this->store->keys(self::THUMBNAILS));
        $logged = $this->logged();
        self::assertStringContainsString('"message":"receipt replaced after check"', $logged);
        self::assertStringNotContainsString(InMemoryObjectStore::etagOf($inspected), $logged);
    }

    public function testAFailedReplacementUpdateLeavesTheFilesForTheRetryWhichFinishesTheJob(): void
    {
        $inspected = ReceiptFixtures::png(40, 30);
        $this->upload('other');
        $this->mock->append(self::readyWithEtagOf($inspected), self::denied('UpdateItem'));

        try {
            $this->processor()->process(self::ourRecord());
            self::fail('the record must be retried');
        } catch (StorageFailure) {
            self::assertCount(1, $this->store->keys(self::RECEIPTS));
        }

        $this->mock->append(StoredReceipt::found('failed', 'image/png', '2026-10-23T11:00:00+00:00', 'unreadable', '2026-10-16T11:01:00Z'));
        $this->processor()->process(self::ourRecord());

        self::assertSame([], $this->store->keys(self::RECEIPTS));
    }

    public function testAReplacementUpdateThatLosesItsConditionReReadsAndAGoneRecordDeletesTheFiles(): void
    {
        $this->upload('other');
        $this->mock->append(self::readyWithEtagOf('inspected'), self::conditionFailed(), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem', 's3 etag ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey(), 'dynamodb UpdateItem', 'dynamodb GetItem', self::original(), self::thumbnail('delete')], $this->events());
    }

    public function testAReplacementUpdateThatLosesItsConditionToAnotherRunLeavesASettledRecordAlone(): void
    {
        $this->upload('other');
        $this->mock->append(self::readyWithEtagOf('inspected'), self::conditionFailed(), self::readyWithEtagOf('other'));

        $this->processor()->process(self::ourRecord());

        self::assertCount(1, $this->store->keys(self::RECEIPTS), 'ready with the ETag of what is stored: nothing to delete');
        $events = $this->events();
        self::assertSame('dynamodb GetItem', end($events), 'only the re-read followed the failed update');
        self::assertNotContains(self::original(), $events);
    }

    public function testADeletedRecordDeletesTheOriginalAndTheThumbnail(): void
    {
        $this->upload(ReceiptFixtures::png(10, 10));
        $this->store->objects[self::THUMBNAILS][StoredReceipt::key()->thumbnailKey()] = 'jpeg';
        $this->mock->append(new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem', self::original(), self::thumbnail('delete')], $this->events());
        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertSame([], $this->store->keys(self::THUMBNAILS));
        self::assertStringContainsString('"message":"receipt obsolete, objects removed"', $this->logged());
    }

    /** From the WP5 reviews: a policy stays valid for 5 minutes after a replace, so a file can land at an old key. */
    public function testAReplacedRecordDeletesOnlyThisKeysObjects(): void
    {
        $other = ReceiptObjectKey::from(StoredReceipt::USER, StoredReceipt::EXPENSE, self::OTHER_RECEIPT);
        $item = StoredReceipt::item();
        $item['objectKey'] = ['S' => $other->originalKey()];
        $item['receiptId'] = ['S' => self::OTHER_RECEIPT];
        $this->upload(ReceiptFixtures::png(10, 10));
        $this->store->objects[self::RECEIPTS][$other->originalKey()] = 'newer upload';
        $this->store->objects[self::THUMBNAILS][$other->thumbnailKey()] = 'newer thumbnail';
        $this->mock->append(new Result(['Item' => $item]));

        $this->processor()->process(self::ourRecord());

        self::assertSame([$other->originalKey()], $this->store->keys(self::RECEIPTS));
        self::assertSame([$other->thumbnailKey()], $this->store->keys(self::THUMBNAILS));
    }

    public function testAnExpiredPendingRecordDeletesBothObjectsAndIsNeverSettled(): void
    {
        $this->upload(ReceiptFixtures::png(10, 10));
        $this->mock->append(StoredReceipt::found('pending', 'image/png', '2026-10-16T11:30:00+00:00'));

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem', self::original(), self::thumbnail('delete')], $this->events());
    }

    public function testAnExpiredFailedRecordAlsoCountsAsGone(): void
    {
        $this->mock->append(StoredReceipt::found('failed', 'image/png', '2026-10-16T11:30:00+00:00', 'unreadable', '2026-10-16T11:01:00Z'));

        $this->processor()->process(self::ourRecord());

        self::assertSame(['dynamodb GetItem', self::original(), self::thumbnail('delete')], $this->events());
    }

    // ---- step 3: the object is not there ----

    public function testAMissingObjectWithAStillPendingRecordIsAnErrorSoItIsRetried(): void
    {
        $this->mock->append(StoredReceipt::found(), StoredReceipt::found());

        try {
            $this->processor()->process(self::ourRecord());
            self::fail('the record must be retried');
        } catch (ReceiptObjectMissing) {
            self::assertSame(['dynamodb GetItem', 's3 get ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey(), 'dynamodb GetItem'], $this->events());
        }
    }

    public function testAMissingObjectWithAGoneRecordIsDoneAfterIdempotentDeletes(): void
    {
        $this->mock->append(StoredReceipt::found(), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame([
            'dynamodb GetItem',
            's3 get ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey(),
            'dynamodb GetItem',
            self::original(),
            self::thumbnail('delete'),
        ], $this->events());
    }

    public function testAMissingObjectWhoseRecordWasSettledMeanwhileIsADuplicate(): void
    {
        $this->mock->append(StoredReceipt::found(), StoredReceipt::ready());

        $this->processor()->process(self::ourRecord());

        self::assertCount(3, $this->events());
        self::assertStringContainsString('"message":"receipt already processed"', $this->logged());
    }

    // ---- step 6: the conditional update loses a race ----

    public function testAReadyUpdateThatFindsTheRecordGoneDeletesBothObjects(): void
    {
        $this->upload(ReceiptFixtures::png(40, 30));
        // GetItem; UpdateItem fails its condition; the repository's re-read finds no record.
        $this->mock->append(StoredReceipt::found(), self::conditionFailed(), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame(
            [self::original(), self::thumbnail('delete')],
            array_slice($this->events(), -2),
            'both objects are removed after the failed update',
        );
        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertSame([], $this->store->keys(self::THUMBNAILS));
    }

    public function testAReadyUpdateThatFindsTheSameUploadAlreadySettledDeletesNothing(): void
    {
        $this->upload(ReceiptFixtures::png(40, 30));
        // GetItem; the update fails its condition; the repository's re-read; the processor's own re-read.
        $this->mock->append(StoredReceipt::found(), self::conditionFailed(), StoredReceipt::ready(), StoredReceipt::ready());

        $this->processor()->process(self::ourRecord());

        self::assertCount(1, $this->store->keys(self::RECEIPTS));
        self::assertCount(1, $this->store->keys(self::THUMBNAILS));
        self::assertStringContainsString('"message":"receipt already processed"', $this->logged());
    }

    // ---- G1: two runs read different bytes while the record was still pending ----

    /**
     * The second run read the newer bytes; the first run settled the record with the ETag of the older ones. The
     * repository reports AlreadyProcessed and the processor must not stop at that.
     */
    public function testALosingRunWhoseBytesDifferFromTheSettledOnesFailsTheRecordAndDeletesBothFiles(): void
    {
        $older = ReceiptFixtures::png(10, 10);
        $this->upload(ReceiptFixtures::png(40, 30));
        $this->mock->append(StoredReceipt::found(), self::conditionFailed(), self::readyWithEtagOf($older), self::readyWithEtagOf($older), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame([
            'dynamodb GetItem',
            's3 get ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey(),
            self::thumbnail('put'),
            'dynamodb UpdateItem',
            'dynamodb GetItem',
            'dynamodb GetItem',
            's3 etag ' . self::RECEIPTS . ' ' . StoredReceipt::key()->originalKey(),
            'dynamodb UpdateItem',
            self::original(),
            self::thumbnail('delete'),
        ], $this->events());
        self::assertSame(['S' => InMemoryObjectStore::etagOf($older)], $this->updated(':stored', 4), 'the replacement update is conditional on the ETag the record holds');
        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertSame([], $this->store->keys(self::THUMBNAILS), 'the thumbnail the losing run wrote over the first one is gone too');
        self::assertStringContainsString('"message":"receipt replaced after check"', $this->logged());
    }

    public function testALosingRunWithTheSameBytesAsTheSettledOnesWritesNothingMore(): void
    {
        $png = ReceiptFixtures::png(40, 30);
        $this->upload($png);
        $this->mock->append(StoredReceipt::found(), self::conditionFailed(), self::readyWithEtagOf($png), self::readyWithEtagOf($png));

        $this->processor()->process(self::ourRecord());

        $updates = array_values(array_filter($this->events(), static fn(string $event): bool => $event === 'dynamodb UpdateItem'));
        self::assertCount(1, $updates, 'only the update that lost');
        self::assertCount(1, $this->store->keys(self::RECEIPTS));
        self::assertCount(1, $this->store->keys(self::THUMBNAILS));
        self::assertStringContainsString('"message":"receipt already processed"', $this->logged());
    }

    public function testAnInvalidFileThatLostToAFailedRecordStillGetsItsOriginalDeleted(): void
    {
        $this->upload('<svg/>');
        $failed = StoredReceipt::found('failed', 'image/png', '2026-10-23T11:00:00+00:00', 'unsupported-type', '2026-10-16T11:01:00Z');
        $this->mock->append(StoredReceipt::found(), self::conditionFailed(), $failed, $failed);

        $this->processor()->process(self::ourRecord());

        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertStringContainsString('"message":"receipt already processed"', $this->logged());
    }

    public function testAValidImageThatLostToAFailedRecordHasOriginalAndThumbnailDeletedAndNothingMoreWritten(): void
    {
        $this->upload(ReceiptFixtures::png(40, 30));
        $failed = StoredReceipt::found('failed', 'image/png', '2026-10-23T11:00:00+00:00', 'unsupported-type', '2026-10-16T11:01:00Z');
        $this->mock->append(StoredReceipt::found(), self::conditionFailed(), $failed, $failed);

        $this->processor()->process(self::ourRecord());

        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertSame([], $this->store->keys(self::THUMBNAILS), 'the thumbnail this run wrote is removed');
        $updates = array_filter($this->events(), static fn(string $event): bool => $event === 'dynamodb UpdateItem');
        self::assertCount(1, $updates, 'only the update that lost');
    }

    public function testAnInvalidFileThatLostToAReadyRecordOfOtherBytesFailsTheRecordAndDeletesTheFiles(): void
    {
        $this->upload('<svg/>');
        $this->store->objects[self::THUMBNAILS][StoredReceipt::key()->thumbnailKey()] = 'jpeg of the first bytes';
        $this->mock->append(StoredReceipt::found(), self::conditionFailed(), self::readyWithEtagOf('first bytes'), self::readyWithEtagOf('first bytes'), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertSame([], $this->store->keys(self::THUMBNAILS));
        self::assertStringContainsString('"message":"receipt replaced after check"', $this->logged());
    }

    public function testALosingRunDoesNotCheckTheEtagTwiceWhenTheReplacementUpdateLosesToo(): void
    {
        $this->upload(ReceiptFixtures::png(40, 30));
        // ... the record the first update lost to is `ready` with other bytes; its replacement update then loses; the
        // re-read shows a record that is gone. No further HEAD, no loop.
        $this->mock->append(StoredReceipt::found(), self::conditionFailed(), self::readyWithEtagOf('a'), self::readyWithEtagOf('a'), self::conditionFailed(), new Result([]));

        $this->processor()->process(self::ourRecord());

        $heads = array_filter($this->events(), static fn(string $event): bool => str_starts_with($event, 's3 etag'));
        self::assertCount(1, $heads);
        self::assertSame([], $this->store->keys(self::RECEIPTS));
    }

    public function testAFailedUpdateThatFindsTheRecordGoneStillLeavesNothingBehind(): void
    {
        $this->upload('<svg/>');
        $this->mock->append(StoredReceipt::found(), self::conditionFailed(), new Result([]));

        $this->processor()->process(self::ourRecord());

        self::assertSame([], $this->store->keys(self::RECEIPTS));
        self::assertSame([], $this->store->keys(self::THUMBNAILS));
    }

    // ---- errors are thrown, never swallowed ----

    public function testADynamoDbFailureIsThrown(): void
    {
        $this->mock->append(self::denied('GetItem'));

        $this->expectException(StorageFailure::class);

        $this->processor()->process(self::ourRecord());
    }

    public function testAnS3FailureIsThrownAndNothingIsSettled(): void
    {
        $this->mock->append(StoredReceipt::found());
        $this->store->failing = true;

        try {
            $this->processor()->process(self::ourRecord());
            self::fail('the record must be retried');
        } catch (ObjectStoreFailure) {
            self::assertSame(['dynamodb GetItem'], array_values(array_filter($this->events(), static fn(string $e): bool => str_starts_with($e, 'dynamodb'))));
        }
    }

    public function testAFailedThumbnailUploadIsThrownBeforeTheRecordIsSettled(): void
    {
        $this->upload(ReceiptFixtures::png(40, 30));
        $this->mock->append(StoredReceipt::found());
        $this->store->failOperation = 'put';

        try {
            $this->processor()->process(self::ourRecord());
            self::fail('the record must be retried');
        } catch (ObjectStoreFailure) {
            self::assertNotContains('dynamodb UpdateItem', $this->events());
        }
    }

    public function testAnErrorHandlerInstalledBeforeIsActiveAgainAfterwards(): void
    {
        $mine = static fn(): bool => true;
        set_error_handler($mine);
        $this->upload(ReceiptFixtures::png(10, 10));
        $this->mock->append(StoredReceipt::found(), new Result([]));

        try {
            $this->processor()->process(self::ourRecord());
            $current = set_error_handler(static fn(): bool => false);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        self::assertSame($mine, $current);
    }

    // ---- the error handler around thumbnails ----

    public function testTheErrorHandlerThrowsOnAnUnsilencedWarning(): void
    {
        $previous = error_reporting(E_ALL);
        try {
            $this->expectException(ErrorException::class);

            ReceiptProcessor::handleError(E_WARNING, 'a warning', __FILE__, __LINE__);
        } finally {
            error_reporting($previous);
        }
    }

    public function testTheErrorHandlerLeavesAWarningThatErrorReportingExcludesToPhp(): void
    {
        $previous = error_reporting(E_ALL & ~E_WARNING);
        try {
            $handled = ReceiptProcessor::handleError(E_WARNING, 'not reported', __FILE__, __LINE__);
        } finally {
            error_reporting($previous);
        }

        self::assertFalse($handled);
    }

    public function testTheErrorHandlerLeavesASilencedErrorToPhp(): void
    {
        $previous = error_reporting(0);
        try {
            $handled = ReceiptProcessor::handleError(E_WARNING, 'silenced with @', __FILE__, __LINE__);
        } finally {
            error_reporting($previous);
        }

        self::assertFalse($handled);
    }

    public function testAnAtSilencedCallUnderTheHandlerDoesNotThrow(): void
    {
        set_error_handler([ReceiptProcessor::class, 'handleError']);
        try {
            $result = @imagecreatefromstring('not an image');
        } finally {
            restore_error_handler();
        }

        self::assertFalse($result);
    }

    // ---- a batch ----

    public function testABatchWithOneThrowingRecordReportsExactlyThatRecord(): void
    {
        $this->mock->append(self::denied('GetItem'));
        $batch = new SqsBatchProcessor($this->processor(), new JsonLogger($this->log));

        $result = $batch->handle(['Records' => [
            self::record('somewhere-else', 'x', 'skipped'),
            self::record(self::RECEIPTS, StoredReceipt::key()->originalKey(), 'throws'),
            self::record('somewhere-else', 'y', 'also-skipped'),
        ]]);

        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'throws']]], $result);
    }
}
