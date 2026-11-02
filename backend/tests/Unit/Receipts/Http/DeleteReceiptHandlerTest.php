<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts\Http;

use App\Auth\Session\Principal;
use App\Http\Handler;
use App\Http\Middleware\ErrorHandlingMiddleware;
use App\Http\Middleware\StorageUnavailableMiddleware;
use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\S3OriginalObjects;
use App\Infrastructure\S3\S3ThumbnailObjects;
use App\Receipts\Http\DeleteReceiptHandler;
use App\Receipts\ReceiptObjectCleaner;
use App\Receipts\ReceiptRepository;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\S3\InMemoryObjectStore;
use App\Tests\Support\S3\TestS3Settings;
use App\Tests\Support\StoredReceipt;
use Aws\Command;
use Aws\CommandInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * `DELETE /expenses/{id}/receipt`: one shared event list shows that the record is deleted BEFORE any object, and what
 * happens to the files in each outcome of the repository's `remove()`.
 */
final class DeleteReceiptHandlerTest extends TestCase
{
    private const string ORIGINAL = 'receipts/' . StoredReceipt::USER . '/' . StoredReceipt::EXPENSE . '/' . StoredReceipt::RECEIPT;
    private const string THUMBNAIL = StoredReceipt::USER . '/' . StoredReceipt::EXPENSE . '/' . StoredReceipt::RECEIPT . '.jpg';

    private MockHandler $mock;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $events;

    /** @var list<array<mixed>> */
    private array $commands = [];

    private InMemoryObjectStore $store;

    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $this->events = new \ArrayObject();
        $this->commands = [];
        $this->store = new InMemoryObjectStore();
        $this->store->events = $this->events;
        $this->store->objects = ['rcp' => [self::ORIGINAL => 'o'], 'thumbs' => [self::THUMBNAIL => 't']];
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->log = $stream;
    }

    private function handler(): Handler
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command->toArray();
            $this->events->append('dynamodb ' . $command->getName());
            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $items = new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName);
        $s3 = TestS3Settings::create();
        $logger = new JsonLogger($this->log);
        $cleaner = new ReceiptObjectCleaner(new S3OriginalObjects($this->store, $s3), new S3ThumbnailObjects($this->store, $s3), $logger, $s3, new FrozenClock());

        return new DeleteReceiptHandler(new ReceiptRepository($items), $cleaner, $logger, new FrozenClock());
    }

    /**
     * @param array<string, string> $query
     */
    private function serve(string $expenseId = StoredReceipt::EXPENSE, array $query = []): Response
    {
        $pipeline = new Pipeline(
            [new ErrorHandlingMiddleware(new JsonLogger($this->log)), new StorageUnavailableMiddleware(new JsonLogger($this->log))],
            $this->handler(),
        );
        $request = (new Request('DELETE', '/api/v1/expenses/' . $expenseId . '/receipt', $query, [], '', [Request::REQUEST_ID => 'req-1', Request::ROUTE_PARAMS => ['id' => $expenseId]]))
            ->withAttribute(Principal::ATTRIBUTE, new Principal(StoredReceipt::USER, 'sessionhash'));

        return $pipeline->handle($request);
    }

    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    public function testALiveReceiptIs204AndTheRecordIsDeletedBeforeAnyObject(): void
    {
        $this->mock->append(StoredReceipt::deleted('ready', 'image/png', null, null, '2026-10-16T11:01:00Z'));

        $response = $this->serve();

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(
            ['dynamodb DeleteItem', 's3 delete rcp ' . self::ORIGINAL, 's3 delete thumbs ' . self::THUMBNAIL],
            $this->events->getArrayCopy(),
        );
        self::assertSame([], $this->store->keys('rcp'));
        self::assertSame([], $this->store->keys('thumbs'));
    }

    public function testThePendingReceiptsFilesAreDeletedToo(): void
    {
        $this->mock->append(StoredReceipt::deleted());

        self::assertSame(204, $this->serve()->status);
        self::assertSame([], $this->store->keys('rcp'));
    }

    public function testTheDeleteUsesTheCallersPartitionAndAllOld(): void
    {
        $this->mock->append(StoredReceipt::deleted());

        $this->serve();

        /** @var array{Key: array{PK: array{S: string}, SK: array{S: string}}, ReturnValues: string} $delete */
        $delete = $this->commands[0];
        self::assertSame('USER#' . StoredReceipt::USER, $delete['Key']['PK']['S']);
        self::assertSame('RCPT#' . StoredReceipt::EXPENSE, $delete['Key']['SK']['S']);
        self::assertSame('ALL_OLD', $delete['ReturnValues']);
    }

    public function testNoReceiptIs404AndNoObjectIsTouched(): void
    {
        $this->mock->append(new Result([]));

        $response = $this->serve();

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
        self::assertSame([], $this->store->calls);
    }

    public function testAnExpiredReceiptIs404ButItsObjectsAreStillDeleted(): void
    {
        $this->mock->append(StoredReceipt::deleted('pending', 'image/png', '2026-10-16T11:30:00+00:00'));

        $response = $this->serve();

        self::assertSame(404, $response->status);
        self::assertSame(
            ['dynamodb DeleteItem', 's3 delete rcp ' . self::ORIGINAL, 's3 delete thumbs ' . self::THUMBNAIL],
            $this->events->getArrayCopy(),
        );
        self::assertSame([], $this->store->keys('rcp'));
    }

    public function testAnS3FailureIsStill204WithTheCleanersErrorLogAndNoUrl(): void
    {
        $this->mock->append(StoredReceipt::deleted());
        $this->store->failing = true;

        $response = $this->serve();

        self::assertSame(204, $response->status);
        $logged = $this->logged();
        self::assertStringContainsString('"level":"error"', $logged);
        self::assertStringContainsString('receipt cleanup failed', $logged);
        foreach (['http://', 'https://', 'X-Amz', 'Signature', 'Policy'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $logged);
        }
    }

    public function testAnUnreadableRecordIsGoneSoOnlyThisExpensesTwoFoldersAreCleanedFromThePrincipal(): void
    {
        $corrupt = StoredReceipt::item();
        $corrupt['objectKey'] = ['S' => 'receipts/not/a/key'];
        $this->mock->append(new Result(['Attributes' => $corrupt]));
        $this->store->objects['rcp']['receipts/' . StoredReceipt::USER . '/other-expense-keep'] = 'k';

        $response = $this->serve(query: ['userId' => StoredReceipt::OTHER_USER, 'prefix' => 'receipts/']);

        self::assertSame(204, $response->status);
        self::assertSame(
            [
                'deletePrefix rcp receipts/' . StoredReceipt::USER . '/' . StoredReceipt::EXPENSE . '/',
                'deletePrefix thumbs ' . StoredReceipt::USER . '/' . StoredReceipt::EXPENSE . '/',
            ],
            $this->store->calls,
        );
        self::assertSame(['receipts/' . StoredReceipt::USER . '/other-expense-keep'], $this->store->keys('rcp'));
        self::assertStringContainsString('"level":"warning"', $this->logged());
        self::assertStringContainsString('receipt record was unreadable', $this->logged());
    }

    public function testOtherStorageFailuresDeleteNothingAndAreA5xx(): void
    {
        $this->mock->append(new DynamoDbException('denied', new Command('DeleteItem'), ['code' => 'AccessDeniedException']));

        $response = $this->serve();

        self::assertSame(500, $response->status);
        self::assertSame([], $this->store->calls, 'the outcome is unknown: no object is deleted');
        self::assertSame(['dynamodb DeleteItem'], $this->events->getArrayCopy());
    }

    public function testThrottledStorageIs503AndDeletesNothing(): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->mock->append(new DynamoDbException('throttled', new Command('DeleteItem'), ['code' => 'ProvisionedThroughputExceededException']));
        }

        $response = $this->serve();

        self::assertSame(503, $response->status);
        self::assertSame([], $this->store->calls);
    }

    public function testAMalformedRouteIdIsTheSame404WithNoCall(): void
    {
        $response = $this->serve(expenseId: 'not-a-uuid');

        self::assertSame(404, $response->status);
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingHappens(): void
    {
        $request = new Request('DELETE', '/x', [], [], '', [Request::ROUTE_PARAMS => ['id' => StoredReceipt::EXPENSE]]);

        try {
            $this->handler()->handle($request);
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->events->getArrayCopy());
        }
    }
}
