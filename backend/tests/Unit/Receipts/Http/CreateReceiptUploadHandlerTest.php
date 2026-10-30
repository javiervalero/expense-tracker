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
use App\Infrastructure\Id\IdGenerator;
use App\Infrastructure\Id\Uuid;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\S3OriginalObjects;
use App\Infrastructure\S3\S3ThumbnailObjects;
use App\Infrastructure\S3\UploadPolicySigner;
use App\Receipts\Http\CreateReceiptUploadHandler;
use App\Receipts\OriginalObjects;
use App\Receipts\ReceiptObjectCleaner;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptRepository;
use App\Receipts\ThumbnailObjects;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\S3\InMemoryObjectStore;
use App\Tests\Support\S3\TestS3Settings;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The handler over a scripted DynamoDB client, an in-memory object port and a real signer whose credential lookup is
 * recorded: one shared event list shows the ORDER of the repository commit, the cleanup and the signing.
 */
final class CreateReceiptUploadHandlerTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const EXPENSE = '5c8e1f3a-2b4d-4a6c-8e0f-1a2b3c4d5e6f';
    private const OLD_RECEIPT = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';
    public const MINTED = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
    private const SECOND_MINTED = 'b2c3d4e5-f6a7-4890-9bcd-ef0123456789';
    private const BODY = '{"contentType":"image/png","size":2048}';

    /** @var \ArrayObject<int, string> everything that happened, in order */
    private \ArrayObject $events;

    private MockHandler $mock;

    private bool $signingFails = false;

    /** @var list<CommandInterface> */
    private array $commands = [];

    /** @var resource */
    private $log;

    /** @var list<string> */
    private array $ids;

    protected function setUp(): void
    {
        $this->events = new \ArrayObject();
        $this->commands = [];
        $this->mock = new MockHandler();
        $this->ids = [self::MINTED, self::SECOND_MINTED];
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->log = $stream;
    }

    private function handler(): Handler
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;
            $this->events->append('dynamodb ' . $command->getName());
            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $items = new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName);
        $s3 = TestS3Settings::create();
        $originals = new class ($this->events) implements OriginalObjects {
            /** @param \ArrayObject<int, string> $events */
            public function __construct(private \ArrayObject $events) {}

            public function get(ReceiptObjectKey $key): ?string
            {
                return null;
            }

            public function delete(ReceiptObjectKey $key): void
            {
                $this->events->append('delete original ' . $key->receiptId());
            }

            public function deleteExpense(string $userId, string $expenseId): void {}
        };
        $thumbnails = new class ($this->events) implements ThumbnailObjects {
            /** @param \ArrayObject<int, string> $events */
            public function __construct(private \ArrayObject $events) {}

            public function put(ReceiptObjectKey $key, string $jpeg): void {}

            public function delete(ReceiptObjectKey $key): void
            {
                $this->events->append('delete thumbnail ' . $key->receiptId());
            }

            public function deleteExpense(string $userId, string $expenseId): void {}
        };
        $signing = new S3Client([
            'version' => '2006-03-01',
            'region' => 'eu-west-1',
            'endpoint' => 'http://localhost:9000',
            'use_path_style_endpoint' => true,
            'credentials' => function (): PromiseInterface {
                $this->events->append('sign');
                if ($this->signingFails) {
                    throw new \RuntimeException('signing failed');
                }

                return Create::promiseFor(new Credentials('localdev', 'localdevsecret'));
            },
        ]);
        $ids = new class ($this->ids) implements IdGenerator {
            /** @param list<string> $ids */
            public function __construct(private array &$ids) {}

            public function generate(): string
            {
                $id = array_shift($this->ids);
                if ($id === null) {
                    throw new \LogicException('The test minted more ids than it scripted.');
                }

                return $id;
            }
        };

        return new CreateReceiptUploadHandler(
            new ReceiptRepository($items),
            new ReceiptObjectCleaner($originals, $thumbnails, new JsonLogger($this->log), $s3),
            new UploadPolicySigner($signing, TestS3Settings::RECEIPTS, new FrozenClock()),
            $ids,
            new FrozenClock(),
        );
    }

    private function serve(string $body = self::BODY, string $expenseId = self::EXPENSE, ?Principal $principal = new Principal(self::USER, 'sessionhash'), string $contentType = 'application/json'): Response
    {
        $pipeline = new Pipeline(
            [new ErrorHandlingMiddleware(new JsonLogger($this->log)), new StorageUnavailableMiddleware(new JsonLogger($this->log))],
            $this->handler(),
        );
        $request = new Request('POST', '/api/v1/expenses/' . $expenseId . '/receipt/upload', [], ['content-type' => $contentType], $body, [Request::REQUEST_ID => 'req-1', Request::ROUTE_PARAMS => ['id' => $expenseId]]);

        return $pipeline->handle($principal === null ? $request : $request->withAttribute(Principal::ATTRIBUTE, $principal));
    }

    /** @return Result<string, mixed> a GetItem that finds nothing, or the ack of a write */
    private static function empty(): Result
    {
        return new Result([]);
    }

    /** @return Result<string, mixed> a GetItem that finds the pending receipt `OLD_RECEIPT` */
    private static function oldReceipt(): Result
    {
        $key = ReceiptObjectKey::from(self::USER, self::EXPENSE, self::OLD_RECEIPT);

        return new Result(['Item' => [
            'PK' => ['S' => 'USER#' . self::USER],
            'SK' => ['S' => 'RCPT#' . self::EXPENSE],
            'receiptId' => ['S' => self::OLD_RECEIPT],
            'objectKey' => ['S' => $key->originalKey()],
            'status' => ['S' => 'pending'],
            'contentType' => ['S' => 'image/png'],
            'size' => ['N' => '1234'],
            'createdAt' => ['S' => '2026-10-16T11:00:00Z'],
            'expiresAt' => ['N' => (string) (new \DateTimeImmutable('2026-10-17T11:00:00+00:00'))->getTimestamp()],
        ]]);
    }

    /** @param list<string> $codes */
    private static function cancelled(array $codes): DynamoDbException
    {
        $reasons = array_map(static fn(string $code): array => ['Code' => $code], $codes);

        return new DynamoDbException('cancelled', new Command('TransactWriteItems'), ['code' => 'TransactionCanceledException', 'body' => ['CancellationReasons' => $reasons]]);
    }

    /** @return array<string, mixed> */
    private static function decoded(Response $response): array
    {
        $data = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function testAFirstUploadAnswers201WithLocationAndTheSignedForm(): void
    {
        $this->mock->append(self::empty(), self::empty());

        $response = $this->serve();

        self::assertSame(201, $response->status);
        self::assertSame(['/api/v1/expenses/' . self::EXPENSE . '/receipt'], $response->headerValues('Location'));
        $data = self::decoded($response);
        self::assertSame(['receiptId', 'url', 'fields', 'expiresAt', 'maxBytes'], array_keys($data));
        self::assertSame(self::MINTED, $data['receiptId']);
        self::assertSame(10_485_760, $data['maxBytes']);
        self::assertSame('2026-10-16T12:05:00Z', $data['expiresAt']);
        self::assertIsString($data['url']);
        self::assertStringContainsString('/rcp', $data['url']);
        self::assertIsArray($data['fields']);
        self::assertSame('receipts/' . self::USER . '/' . self::EXPENSE . '/' . self::MINTED, $data['fields']['key'] ?? null);
        self::assertSame('image/png', $data['fields']['Content-Type'] ?? null);
        self::assertArrayHasKey('Policy', $data['fields']);
        self::assertArrayHasKey('X-Amz-Signature', $data['fields']);
    }

    public function testTheOrderIsReadThenCommitThenSignAndNothingIsDeletedOnAFirstUpload(): void
    {
        $this->mock->append(self::empty(), self::empty());

        $this->serve();

        self::assertSame(['dynamodb GetItem', 'dynamodb TransactWriteItems', 'sign'], $this->events->getArrayCopy());
    }

    public function testAReplaceSignsAfterTheCommitAndOnlyThenDeletesTheOldOriginalAndThumbnail(): void
    {
        $this->mock->append(self::oldReceipt(), self::empty());

        $response = $this->serve();

        self::assertSame(201, $response->status);
        self::assertSame(
            ['dynamodb GetItem', 'dynamodb TransactWriteItems', 'sign', 'delete original ' . self::OLD_RECEIPT, 'delete thumbnail ' . self::OLD_RECEIPT],
            $this->events->getArrayCopy(),
        );
        self::assertSame(self::MINTED, self::decoded($response)['receiptId']);
    }

    public function testAFailingCleanupAfterAReplaceStillAnswers201(): void
    {
        $this->mock->append(self::oldReceipt(), self::empty());

        $response = $this->serveWithFailingObjectStore();

        self::assertSame(201, $response->status);
    }

    public function testEveryCallMintsAFreshV4ReceiptId(): void
    {
        $this->mock->append(self::empty(), self::empty(), self::empty(), self::empty());

        $first = self::decoded($this->serve())['receiptId'];
        $second = self::decoded($this->serve())['receiptId'];

        self::assertSame([self::MINTED, self::SECOND_MINTED], [$first, $second]);
        self::assertNotSame($first, $second);
        self::assertTrue(Uuid::isValid(self::MINTED));
    }

    public function testTheWrittenRecordIsPendingForTheMintedIdUnderTheCallersPartition(): void
    {
        $this->mock->append(self::empty(), self::empty());

        $this->serve();

        /** @var array{TransactItems: list<array<string, array<string, mixed>>>} $transaction */
        $transaction = $this->commands[1]->toArray();
        $put = $transaction['TransactItems'][1]['Put']['Item'] ?? null;
        self::assertIsArray($put);
        self::assertSame(['S' => 'USER#' . self::USER], $put['PK']);
        self::assertSame(['S' => 'RCPT#' . self::EXPENSE], $put['SK']);
        self::assertSame(['S' => self::MINTED], $put['receiptId']);
        self::assertSame(['S' => 'pending'], $put['status']);
        self::assertSame(['S' => 'image/png'], $put['contentType']);
        self::assertSame(['N' => '2048'], $put['size']);
        self::assertSame(['N' => (string) (new \DateTimeImmutable('2026-10-17T12:00:00+00:00'))->getTimestamp()], $put['expiresAt']);
    }

    public function testAnExpenseThatIsNotTheCallersIs404AndNothingIsDeletedOrSigned(): void
    {
        $this->mock->append(self::oldReceipt(), self::cancelled(['ConditionalCheckFailed', 'None']));

        $response = $this->serve();

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
        self::assertSame(['dynamodb GetItem', 'dynamodb TransactWriteItems'], $this->events->getArrayCopy());
    }

    public function testAMalformedRouteIdIsTheSame404WithNoStorageCall(): void
    {
        $response = $this->serve(expenseId: 'not-a-uuid');

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testAnInvalidBodyIs422AndNothingIsReadWrittenOrSigned(): void
    {
        $response = $this->serve('{"contentType":"image/gif","size":0}');

        self::assertSame(422, $response->status);
        self::assertStringContainsString('/contentType', $response->body);
        self::assertStringContainsString('/size', $response->body);
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testTheRouteIdIsCheckedBeforeTheBody(): void
    {
        $response = $this->serve('not json at all', expenseId: 'not-a-uuid');

        self::assertSame(404, $response->status);
    }

    public function testABodyThatIsNotAnObjectIs400AndNothingIsTouched(): void
    {
        $response = $this->serve('"image/png"');

        self::assertSame(400, $response->status);
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testAnotherMediaTypeIs415(): void
    {
        $response = $this->serve(contentType: 'text/plain');

        self::assertSame(415, $response->status);
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testAChangedReceiptIs409AndNothingIsDeletedOrSigned(): void
    {
        $this->mock->append(self::oldReceipt(), self::cancelled(['None', 'ConditionalCheckFailed']));

        $response = $this->serve();

        self::assertSame(409, $response->status);
        self::assertSame(['dynamodb GetItem', 'dynamodb TransactWriteItems'], $this->events->getArrayCopy());
        self::assertStringNotContainsString(self::OLD_RECEIPT, $response->body);
    }

    public function testAConflictingTransactionIs503WithRetryAfterAndNothingIsDeletedOrSigned(): void
    {
        $this->mock->append(self::oldReceipt(), self::cancelled(['None', 'TransactionConflict']));

        $response = $this->serve();

        self::assertSame(503, $response->status);
        self::assertSame(['1'], $response->headerValues('Retry-After'));
        self::assertSame(['dynamodb GetItem', 'dynamodb TransactWriteItems'], $this->events->getArrayCopy());
    }

    public function testThrottledStorageIs503AndNothingIsSigned(): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->mock->append(new DynamoDbException('throttled', new Command('GetItem'), ['code' => 'ProvisionedThroughputExceededException']));
        }

        $response = $this->serve();

        self::assertSame(503, $response->status);
        self::assertNotContains('sign', $this->events->getArrayCopy());
    }

    public function testACancellationThatIsNeitherAFailedConditionNorOverloadIsAGeneric500(): void
    {
        $this->mock->append(self::empty(), self::cancelled(['None', 'ValidationError']));

        $response = $this->serve();

        self::assertSame(500, $response->status);
        self::assertNotContains('sign', $this->events->getArrayCopy());
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingHappens(): void
    {
        $request = new Request('POST', '/x', [], ['content-type' => 'application/json'], self::BODY, [Request::ROUTE_PARAMS => ['id' => self::EXPENSE]]);

        try {
            $this->handler()->handle($request);
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->events->getArrayCopy());
        }
    }

    public function testNeitherThePolicyNorTheSignatureNorTheUrlIsLogged(): void
    {
        $this->mock->append(self::oldReceipt(), self::empty());

        $data = self::decoded($this->serve());
        rewind($this->log);
        $logged = (string) stream_get_contents($this->log);

        self::assertIsArray($data['fields']);
        foreach (['Policy', 'X-Amz-Signature'] as $name) {
            self::assertIsString($data['fields'][$name]);
            self::assertStringNotContainsString($data['fields'][$name], $logged);
        }
        self::assertIsString($data['url']);
        self::assertStringNotContainsString($data['url'], $logged);
    }

    public function testTheLogLineOfAFailedCleanupHoldsNoPolicySignatureOrUrl(): void
    {
        $this->mock->append(self::oldReceipt(), self::empty());

        $data = self::decoded($this->serveWithFailingObjectStore());
        rewind($this->log);
        $logged = (string) stream_get_contents($this->log);

        self::assertStringContainsString('receipt cleanup failed', $logged, 'the cleaner did log its failure');
        self::assertIsArray($data['fields']);
        foreach (['Policy', 'X-Amz-Signature', 'X-Amz-Credential'] as $name) {
            self::assertIsString($data['fields'][$name]);
            self::assertStringNotContainsString($data['fields'][$name], $logged);
        }
        self::assertIsString($data['url']);
        self::assertStringNotContainsString($data['url'], $logged);
    }

    public function testThePolicyPinsTheDeclaredSizeAndMaxBytesStaysTheCap(): void
    {
        $this->mock->append(self::empty(), self::empty());

        $data = self::decoded($this->serve());

        self::assertIsArray($data['fields']);
        self::assertIsString($data['fields']['Policy']);
        $policy = json_decode((string) base64_decode($data['fields']['Policy'], true), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($policy);
        self::assertIsArray($policy['conditions'] ?? null);
        self::assertContains(['content-length-range', 2048, 2048], $policy['conditions']);
        // The literal pins the contract value (ReceiptUpload.maxBytes) on purpose, not ReceiptLimits::MAX_BYTES.
        self::assertSame(10_485_760, $data['maxBytes']);
    }

    public function testIfSigningFailsTheUnreferencedOldFilesAreStillDeleted(): void
    {
        $this->signingFails = true;
        $this->mock->append(self::oldReceipt(), self::empty());

        $response = $this->serve();

        self::assertSame(500, $response->status);
        // The record already points at the new key, so the old files are garbage whether or not signing works.
        self::assertSame(
            ['dynamodb GetItem', 'dynamodb TransactWriteItems', 'sign', 'delete original ' . self::OLD_RECEIPT, 'delete thumbnail ' . self::OLD_RECEIPT],
            $this->events->getArrayCopy(),
        );
    }

    private function serveWithFailingObjectStore(): Response
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $items = new ItemStore(DynamoDbClientFactory::create($settings, $this->mock), $settings->tableName);
        $failing = new InMemoryObjectStore();
        $failing->failing = true;
        $s3 = TestS3Settings::create();
        $cleaner = new ReceiptObjectCleaner(
            new S3OriginalObjects($failing, $s3),
            new S3ThumbnailObjects($failing, $s3),
            new JsonLogger($this->log),
            $s3,
        );
        $signing = new S3Client([
            'version' => '2006-03-01', 'region' => 'eu-west-1', 'endpoint' => 'http://localhost:9000', 'use_path_style_endpoint' => true,
            'credentials' => static fn(): PromiseInterface => Create::promiseFor(new Credentials('localdev', 'localdevsecret')),
        ]);
        $ids = new class implements IdGenerator {
            public function generate(): string
            {
                return CreateReceiptUploadHandlerTest::MINTED;
            }
        };
        $handler = new CreateReceiptUploadHandler(new ReceiptRepository($items), $cleaner, new UploadPolicySigner($signing, TestS3Settings::RECEIPTS, new FrozenClock()), $ids, new FrozenClock());
        $request = new Request('POST', '/x', [], ['content-type' => 'application/json'], self::BODY, [Request::ROUTE_PARAMS => ['id' => self::EXPENSE]]);

        return $handler->handle($request->withAttribute(Principal::ATTRIBUTE, new Principal(self::USER, 'sessionhash')));
    }
}
