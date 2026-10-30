<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts;

use App\Auth\Session\Principal;
use App\Expenses\ExpenseNotFound;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Receipts\FailureReason;
use App\Receipts\Receipt;
use App\Receipts\ReceiptChanged;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptNotPending;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptRepository;
use App\Receipts\ReceiptStatus;
use App\Receipts\SettleResult;
use Aws\CommandInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use DateTimeImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The branches of ReceiptRepository that need a scripted DynamoDB answer: the exact requests, cancellation reasons by
 * position, the re-read after a failed condition, and every hydration check. Ids and times are invented.
 */
final class ReceiptRepositoryTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_USER = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const EXPENSE = '5c8e1f3a-2b4d-4a6c-8e0f-1a2b3c4d5e6f';
    private const OTHER_EXPENSE = '7e1a2b3c-4d5e-4f60-8a1b-2c3d4e5f6071';
    private const OLD_RECEIPT = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';
    private const NEW_RECEIPT = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
    private const CREATED = '2026-10-29T10:00:00+00:00';
    private const EXPIRES = '2026-10-30T10:00:00+00:00';
    private const NOW = '2026-10-29T12:00:00+00:00';

    private MockHandler $mock;

    /** @var list<CommandInterface> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->commands = [];
        $this->mock = new MockHandler();
    }

    private function repository(): ReceiptRepository
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;
            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };

        return new ReceiptRepository(new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName));
    }

    private static function owner(string $userId = self::USER): Principal
    {
        return new Principal($userId, str_repeat('a', 64));
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    private static function keyOf(string $receiptId = self::OLD_RECEIPT): ReceiptObjectKey
    {
        return ReceiptObjectKey::from(self::USER, self::EXPENSE, $receiptId);
    }

    private static function pendingReceipt(string $receiptId = self::NEW_RECEIPT): Receipt
    {
        return new Receipt(self::keyOf($receiptId), ReceiptStatus::Pending, ReceiptContentType::Png, 1234, self::at(self::CREATED), self::at(self::EXPIRES), null, null);
    }

    /**
     * @param array<string, string|int|null> $override a null value removes the attribute
     *
     * @return array<string, array<string, string>>
     */
    private static function rawItem(array $override = []): array
    {
        $fields = $override + [
            'PK' => 'USER#' . self::USER,
            'SK' => 'RCPT#' . self::EXPENSE,
            'receiptId' => self::OLD_RECEIPT,
            'objectKey' => self::keyOf()->originalKey(),
            'status' => 'pending',
            'contentType' => 'image/png',
            'size' => 1234,
            'createdAt' => '2026-10-29T10:00:00Z',
            'expiresAt' => self::at(self::EXPIRES)->getTimestamp(),
        ];
        $raw = [];
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $raw[$name] = is_string($value) ? ['S' => $value] : ['N' => (string) $value];
            }
        }

        return $raw;
    }

    /**
     * @param array<string, string|int|null> $override
     *
     * @return array<string, string|int|null>
     */
    private static function readyFields(array $override = []): array
    {
        return $override + ['status' => 'ready', 'expiresAt' => null, 'processedAt' => '2026-10-29T10:01:00Z'];
    }

    /**
     * @param array<string, string|int|null> $override
     *
     * @return Result<string, mixed>
     */
    private static function stored(array $override = []): Result
    {
        return new Result(['Item' => self::rawItem($override)]);
    }

    private static function conditionFailed(string $operation = 'UpdateItem'): DynamoDbException
    {
        return new DynamoDbException('failed', new \Aws\Command($operation), ['code' => 'ConditionalCheckFailedException']);
    }

    /**
     * @param list<string> $codes
     */
    private static function cancelled(array $codes): DynamoDbException
    {
        $reasons = array_map(static fn(string $code): array => ['Code' => $code], $codes);

        return new DynamoDbException('cancelled', new \Aws\Command('TransactWriteItems'), ['code' => 'TransactionCanceledException', 'body' => ['CancellationReasons' => $reasons]]);
    }

    private function assertCommandNames(string ...$names): void
    {
        self::assertSame($names, array_map(static fn(CommandInterface $command): string => $command->getName(), $this->commands));
    }

    /**
     * The body of the nth operation of the transaction (second command), as plain strings: `ConditionExpression`, and
     * the typed maps `Key`, `Item` and `ExpressionAttributeValues` flattened to `name => value`.
     *
     * @return array<string, string|array<string, string>>
     */
    private function transactionBody(int $index): array
    {
        $items = $this->commands[1]['TransactItems'] ?? null;
        self::assertIsArray($items);
        $operation = $items[$index] ?? null;
        self::assertIsArray($operation);
        self::assertCount(1, $operation);

        return ['kind' => (string) array_key_first($operation)] + self::plainBody(reset($operation));
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    private function commandBody(int $index): array
    {
        $command = $this->commands[$index];

        return self::plainBody($command->toArray());
    }

    /**
     * @param array<string, string|array<string, string>> $body
     */
    private static function valueOf(array $body, string $name): string
    {
        $values = $body['ExpressionAttributeValues'] ?? null;
        self::assertIsArray($values);
        self::assertArrayHasKey($name, $values);

        return $values[$name];
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    private static function plainBody(mixed $body): array
    {
        self::assertIsArray($body);
        $plain = [];
        foreach ($body as $field => $value) {
            if (is_array($value) && in_array($field, ['Key', 'Item', 'ExpressionAttributeValues'], true)) {
                $map = [];
                foreach ($value as $name => $typed) {
                    self::assertIsArray($typed);
                    $scalar = reset($typed);
                    self::assertIsString($scalar);
                    $map[(string) $name] = $scalar;
                }
                $plain[$field] = $map;
            } elseif (is_string($value)) {
                $plain[(string) $field] = $value;
            }
        }

        return $plain;
    }

    private function assertCorrupt(callable $call, string $operation): void
    {
        try {
            $call();
            self::fail('A corrupt item must not be returned.');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
            self::assertSame($operation, $failure->operation);
        }
    }

    // find

    public function testFindReadsTheReceiptRecordOfTheCallerStronglyConsistent(): void
    {
        $this->mock->append(self::stored());

        $receipt = $this->repository()->find(self::owner(), self::EXPENSE, self::at(self::NOW));

        $this->assertCommandNames('GetItem');
        self::assertSame(['PK' => 'USER#' . self::USER, 'SK' => 'RCPT#' . self::EXPENSE], $this->commandBody(0)['Key'] ?? null);
        self::assertTrue($this->commands[0]['ConsistentRead']);
        self::assertNotNull($receipt);
        self::assertSame(self::OLD_RECEIPT, $receipt->receiptId());
        self::assertSame(ReceiptStatus::Pending, $receipt->status);
        self::assertSame(ReceiptContentType::Png, $receipt->contentType);
        self::assertSame(1234, $receipt->size);
        self::assertSame(self::at(self::EXPIRES)->getTimestamp(), $receipt->expiresAt?->getTimestamp());
    }

    public function testFindWithoutARecordIsNull(): void
    {
        $this->mock->append(new Result([]));

        self::assertNull($this->repository()->find(self::owner(), self::EXPENSE, self::at(self::NOW)));
    }

    public function testFindHidesAPendingRecordThatExpiredEvenIfDynamoDbStillHasIt(): void
    {
        $this->mock->append(self::stored());

        self::assertNull($this->repository()->find(self::owner(), self::EXPENSE, self::at(self::EXPIRES)));
    }

    public function testFindHidesAFailedRecordThatExpired(): void
    {
        $this->mock->append(self::stored(['status' => 'failed', 'failureReason' => 'unreadable', 'processedAt' => '2026-10-29T10:01:00Z']));

        self::assertNull($this->repository()->find(self::owner(), self::EXPENSE, self::at('2026-10-31T00:00:00+00:00')));
    }

    public function testFindShowsAFailedRecordBeforeItExpires(): void
    {
        $this->mock->append(self::stored(['status' => 'failed', 'failureReason' => 'unreadable', 'processedAt' => '2026-10-29T10:01:00Z']));

        $receipt = $this->repository()->find(self::owner(), self::EXPENSE, self::at(self::NOW));

        self::assertSame(FailureReason::Unreadable, $receipt?->failureReason);
    }

    public function testFindShowsAReadyRecordWhateverTheClock(): void
    {
        $this->mock->append(self::stored(self::readyFields()));

        $receipt = $this->repository()->find(self::owner(), self::EXPENSE, self::at('2100-01-01T00:00:00+00:00'));

        self::assertSame(ReceiptStatus::Ready, $receipt?->status);
        self::assertNull($receipt->expiresAt);
        self::assertSame('2026-10-29T10:01:00+00:00', $receipt->processedAt?->format('c'));
    }

    public function testFindRefusesAMalformedExpenseIdWithoutSendingARequest(): void
    {
        try {
            $this->repository()->find(self::owner(), 'not-a-uuid', self::at(self::NOW));
            self::fail('A malformed id must be refused.');
        } catch (InvalidArgumentException) {
            $this->assertCommandNames();
        }
    }

    // hydration

    /**
     * @param array<string, string|int|null> $override
     */
    #[DataProvider('corruptItems')]
    public function testFindOfACorruptItemIsAStorageFailureNamingGetItem(array $override): void
    {
        $this->mock->append(self::stored($override));

        $this->assertCorrupt(fn() => $this->repository()->find(self::owner(), self::EXPENSE, self::at(self::NOW)), 'GetItem');
    }

    /**
     * @return array<string, array{array<string, string|int|null>}>
     */
    public static function corruptItems(): array
    {
        $keyFor = static fn(string $user, string $expense, string $receipt): string => ReceiptObjectKey::from($user, $expense, $receipt)->originalKey();

        return [
            'etag that is not a string' => [self::readyFields(['objectEtag' => 5])],
            'empty etag' => [self::readyFields(['objectEtag' => ''])],
            'etag on a pending record' => [['objectEtag' => 'abc123']],
            'object key that does not parse' => [['objectKey' => 'receipts/not/a/key']],
            'object key with an upper-case id' => [['objectKey' => strtoupper(self::keyOf()->originalKey())]],
            'object key with a fourth segment' => [['objectKey' => self::keyOf()->originalKey() . '/x']],
            'object key of another user' => [['objectKey' => $keyFor(self::OTHER_USER, self::EXPENSE, self::OLD_RECEIPT)]],
            'object key of another expense' => [['objectKey' => $keyFor(self::USER, self::OTHER_EXPENSE, self::OLD_RECEIPT)]],
            'receipt id that is not the key\'s' => [['receiptId' => self::NEW_RECEIPT]],
            'missing object key' => [['objectKey' => null]],
            'object key of the wrong type' => [['objectKey' => 5]],
            'partition of another user' => [['PK' => 'USER#' . self::OTHER_USER]],
            'sort key of another expense' => [['SK' => 'RCPT#' . self::OTHER_EXPENSE]],
            'sort key of an expense item' => [['SK' => 'EXP#' . self::EXPENSE]],
            'unknown status' => [['status' => 'done']],
            'status of the wrong type' => [['status' => 1]],
            'unknown content type' => [['contentType' => 'image/gif']],
            'missing content type' => [['contentType' => null]],
            'size zero' => [['size' => 0]],
            'size above the limit' => [['size' => 10_485_761]],
            'size of the wrong type' => [['size' => '1234']],
            'created at that is not a stored time' => [['createdAt' => '2026-10-29 10:00:00']],
            'missing created at' => [['createdAt' => null]],
            'pending without an expiry' => [['expiresAt' => null]],
            'expiry of the wrong type' => [['expiresAt' => '1788000000']],
            'expiry before creation' => [['expiresAt' => 1]],
            'ready with an expiry' => [self::readyFields(['expiresAt' => 1_800_000_000])],
            'ready without a processing time' => [self::readyFields(['processedAt' => null])],
            'pending with a processing time' => [['processedAt' => '2026-10-29T10:01:00Z']],
            'failed without a reason' => [['status' => 'failed', 'processedAt' => '2026-10-29T10:01:00Z']],
            'failed with an unknown reason' => [['status' => 'failed', 'failureReason' => 'because', 'processedAt' => '2026-10-29T10:01:00Z']],
            'pending with a reason' => [['failureReason' => 'unreadable']],
        ];
    }

    // findByKey (worker)

    public function testFindByKeyReadsThePartitionOfTheKeyAndReturnsTheRecordWhateverItsKeyOrExpiry(): void
    {
        $this->mock->append(self::stored(['receiptId' => self::NEW_RECEIPT, 'objectKey' => self::keyOf(self::NEW_RECEIPT)->originalKey()]));

        $receipt = $this->repository()->findByKey(self::keyOf());

        $this->assertCommandNames('GetItem');
        self::assertSame(['PK' => 'USER#' . self::USER, 'SK' => 'RCPT#' . self::EXPENSE], $this->commandBody(0)['Key'] ?? null);
        self::assertSame(self::NEW_RECEIPT, $receipt?->receiptId());
    }

    public function testFindByKeyWithoutARecordIsNull(): void
    {
        $this->mock->append(new Result([]));

        self::assertNull($this->repository()->findByKey(self::keyOf()));
    }

    public function testFindByKeyOfACorruptItemIsAStorageFailure(): void
    {
        $this->mock->append(self::stored(['PK' => 'USER#' . self::OTHER_USER]));

        $this->assertCorrupt(fn() => $this->repository()->findByKey(self::keyOf()), 'GetItem');
    }

    // startUpload

    public function testStartUploadWithNoCurrentReceiptSendsOneTransactionAndReturnsNull(): void
    {
        $this->mock->append(new Result([]), new Result([]));

        $old = $this->repository()->startUpload(self::owner(), self::EXPENSE, self::pendingReceipt());

        self::assertNull($old);
        $this->assertCommandNames('GetItem', 'TransactWriteItems');
        $check = $this->transactionBody(0);
        self::assertSame('ConditionCheck', $check['kind']);
        self::assertSame('attribute_exists(PK)', $check['ConditionExpression']);
        self::assertSame(['PK' => 'USER#' . self::USER, 'SK' => 'EXP#' . self::EXPENSE], $check['Key']);
        $put = $this->transactionBody(1);
        self::assertSame('Put', $put['kind']);
        self::assertSame('attribute_not_exists(PK)', $put['ConditionExpression']);
        self::assertArrayNotHasKey('ExpressionAttributeValues', $put);
        self::assertSame([
            'PK' => 'USER#' . self::USER,
            'SK' => 'RCPT#' . self::EXPENSE,
            'receiptId' => self::NEW_RECEIPT,
            'objectKey' => self::keyOf(self::NEW_RECEIPT)->originalKey(),
            'status' => 'pending',
            'contentType' => 'image/png',
            'size' => '1234',
            'createdAt' => '2026-10-29T10:00:00Z',
            'expiresAt' => (string) self::at(self::EXPIRES)->getTimestamp(),
        ], $put['Item'] ?? null);
    }

    public function testStartUploadOverACurrentReceiptConditionsOnItsIdAndReturnsItsKey(): void
    {
        $this->mock->append(self::stored(), new Result([]));

        $old = $this->repository()->startUpload(self::owner(), self::EXPENSE, self::pendingReceipt());

        self::assertEquals(self::keyOf(), $old);
        $put = $this->transactionBody(1);
        self::assertSame('attribute_not_exists(PK) OR receiptId = :old', $put['ConditionExpression']);
        self::assertSame([':old' => self::OLD_RECEIPT], $put['ExpressionAttributeValues'] ?? null);
    }

    public function testStartUploadWithTheExpenseGoneIsExpenseNotFoundByPositionZero(): void
    {
        $this->mock->append(new Result([]), self::cancelled(['ConditionalCheckFailed', 'None']));

        $this->expectException(ExpenseNotFound::class);

        $this->repository()->startUpload(self::owner(), self::EXPENSE, self::pendingReceipt());
    }

    public function testStartUploadWithTheReceiptChangedIsReceiptChangedByPositionOne(): void
    {
        $this->mock->append(new Result([]), self::cancelled(['None', 'ConditionalCheckFailed']));

        $this->expectException(ReceiptChanged::class);

        $this->repository()->startUpload(self::owner(), self::EXPENSE, self::pendingReceipt());
    }

    public function testStartUploadWhenBothGuardsFailReportsTheExpense(): void
    {
        $this->mock->append(new Result([]), self::cancelled(['ConditionalCheckFailed', 'ConditionalCheckFailed']));

        $this->expectException(ExpenseNotFound::class);

        $this->repository()->startUpload(self::owner(), self::EXPENSE, self::pendingReceipt());
    }

    #[DataProvider('cancellationsThatAreNotAGuard')]
    public function testStartUploadRethrowsACancellationThatIsNotAFailedGuard(string $first, string $second): void
    {
        $this->mock->append(new Result([]), self::cancelled([$first, $second]));

        $this->expectException(TransactionCancelled::class);

        $this->repository()->startUpload(self::owner(), self::EXPENSE, self::pendingReceipt());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cancellationsThatAreNotAGuard(): array
    {
        return [
            'conflict on the expense' => ['TransactionConflict', 'None'],
            'throttled receipt' => ['None', 'ThrottlingError'],
            'no reasons' => ['', ''],
        ];
    }

    public function testStartUploadWithNoReasonsAtAllRethrowsTheCancellation(): void
    {
        $this->mock->append(new Result([]), self::cancelled([]));

        $this->expectException(TransactionCancelled::class);

        $this->repository()->startUpload(self::owner(), self::EXPENSE, self::pendingReceipt());
    }

    public function testStartUploadOverACorruptCurrentReceiptIsAStorageFailureAndWritesNothing(): void
    {
        $this->mock->append(self::stored(['status' => 'done']));

        $this->assertCorrupt(fn() => $this->repository()->startUpload(self::owner(), self::EXPENSE, self::pendingReceipt()), 'GetItem');
        $this->assertCommandNames('GetItem');
    }

    public function testStartUploadRefusesAReceiptOfAnotherUserWithoutSendingARequest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->repository()->startUpload(self::owner(self::OTHER_USER), self::EXPENSE, self::pendingReceipt());
        } finally {
            $this->assertCommandNames();
        }
    }

    public function testStartUploadRefusesAReceiptOfAnotherExpenseWithoutSendingARequest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->repository()->startUpload(self::owner(), self::OTHER_EXPENSE, self::pendingReceipt());
        } finally {
            $this->assertCommandNames();
        }
    }

    public function testStartUploadRefusesTheReceiptThatIsAlreadyCurrentAndWritesNothing(): void
    {
        $this->mock->append(self::stored());
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->repository()->startUpload(self::owner(), self::EXPENSE, self::pendingReceipt(self::OLD_RECEIPT));
        } finally {
            $this->assertCommandNames('GetItem');
        }
    }

    public function testStartUploadRefusesAReceiptThatIsNotPending(): void
    {
        $ready = new Receipt(self::keyOf(self::NEW_RECEIPT), ReceiptStatus::Ready, ReceiptContentType::Png, 1234, self::at(self::CREATED), null, null, self::at(self::NOW));

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->repository()->startUpload(self::owner(), self::EXPENSE, $ready);
        } finally {
            $this->assertCommandNames();
        }
    }

    // remove

    public function testRemoveDeletesTheRecordAskingForTheOldItemAndReturnsIt(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem()]));

        $old = $this->repository()->remove(self::owner(), self::EXPENSE);

        $this->assertCommandNames('DeleteItem');
        self::assertSame(['PK' => 'USER#' . self::USER, 'SK' => 'RCPT#' . self::EXPENSE], $this->commandBody(0)['Key'] ?? null);
        self::assertSame('ALL_OLD', $this->commandBody(0)['ReturnValues'] ?? null);
        self::assertSame(self::OLD_RECEIPT, $old?->receiptId());
    }

    public function testRemoveWithNothingThereIsNull(): void
    {
        $this->mock->append(new Result([]));

        self::assertNull($this->repository()->remove(self::owner(), self::EXPENSE));
    }

    public function testRemoveOfACorruptOldItemSendsTheDeleteItemThenFailsAsCorrupt(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem(['PK' => 'USER#' . self::OTHER_USER])]));

        $this->assertCorrupt(fn() => $this->repository()->remove(self::owner(), self::EXPENSE), 'DeleteItem');

        $this->assertCommandNames('DeleteItem');
    }

    // markReady

    public function testMarkReadySendsTheConditionalUpdateWithTheClockAsATimestamp(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem(self::readyFields())]));
        $now = new DateTimeImmutable('2026-10-29T14:00:00+02:00');

        $result = $this->repository()->markReady(self::keyOf(), ReceiptContentType::Jpeg, $now, null);

        self::assertSame(SettleResult::Applied, $result);
        $this->assertCommandNames('UpdateItem');
        $command = $this->commandBody(0);
        self::assertSame(['PK' => 'USER#' . self::USER, 'SK' => 'RCPT#' . self::EXPENSE], $command['Key'] ?? null);
        self::assertSame('#objectKey = :key AND #status = :pending AND #expiresAt > :now', $command['ConditionExpression']);
        self::assertSame(self::keyOf()->originalKey(), self::valueOf($command, ':key'));
        self::assertSame('pending', self::valueOf($command, ':pending'));
        self::assertSame((string) $now->getTimestamp(), self::valueOf($command, ':now'));
        self::assertSame('image/jpeg', self::valueOf($command, ':detected'));
        self::assertSame('2026-10-29T12:00:00Z', self::valueOf($command, ':processedAt'));
        self::assertSame(
            'SET #status = :ready, #contentType = :detected, #processedAt = :processedAt REMOVE #expiresAt, #failureReason',
            $command['UpdateExpression'],
        );
        self::assertSame('ready', self::valueOf($command, ':ready'));
    }

    public function testMarkReadyAfterTheRecordWasSettledReportsAlreadyProcessed(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored(self::readyFields()));

        $result = $this->repository()->markReady(self::keyOf(), ReceiptContentType::Png, self::at(self::NOW), null);

        self::assertSame(SettleResult::AlreadyProcessed, $result);
        $this->assertCommandNames('UpdateItem', 'GetItem');
    }

    public function testMarkReadyAfterAFailureAlsoReportsAlreadyProcessed(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored(['status' => 'failed', 'failureReason' => 'unreadable', 'processedAt' => '2026-10-29T10:01:00Z']));

        self::assertSame(SettleResult::AlreadyProcessed, $this->repository()->markReady(self::keyOf(), ReceiptContentType::Png, self::at(self::NOW), null));
    }

    public function testMarkReadyForAnotherKeyIsNotPending(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored(['receiptId' => self::NEW_RECEIPT, 'objectKey' => self::keyOf(self::NEW_RECEIPT)->originalKey()]));

        $this->expectException(ReceiptNotPending::class);

        $this->repository()->markReady(self::keyOf(), ReceiptContentType::Png, self::at(self::NOW), null);
    }

    public function testMarkReadyForAnotherKeyThatIsSettledIsStillNotPending(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored(self::readyFields(['receiptId' => self::NEW_RECEIPT, 'objectKey' => self::keyOf(self::NEW_RECEIPT)->originalKey()])));

        $this->expectException(ReceiptNotPending::class);

        $this->repository()->markReady(self::keyOf(), ReceiptContentType::Png, self::at(self::NOW), null);
    }

    public function testMarkReadyOfAnExpiredPendingRecordIsNotPending(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored());

        $this->expectException(ReceiptNotPending::class);

        $this->repository()->markReady(self::keyOf(), ReceiptContentType::Png, self::at(self::EXPIRES), null);
    }

    public function testMarkReadyOfAMissingRecordIsNotPending(): void
    {
        $this->mock->append(self::conditionFailed(), new Result([]));

        $this->expectException(ReceiptNotPending::class);

        $this->repository()->markReady(self::keyOf(), ReceiptContentType::Png, self::at(self::NOW), null);
    }

    public function testMarkReadyRethrowsAStorageFailure(): void
    {
        $this->mock->append(new DynamoDbException('denied', new \Aws\Command('UpdateItem'), ['code' => 'AccessDeniedException']));

        $this->expectException(StorageFailure::class);

        $this->repository()->markReady(self::keyOf(), ReceiptContentType::Png, self::at(self::NOW), null);
    }

    public function testMarkReadyWhoseReReadIsCorruptIsAStorageFailure(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored(['PK' => 'USER#' . self::OTHER_USER]));

        $this->assertCorrupt(fn() => $this->repository()->markReady(self::keyOf(), ReceiptContentType::Png, self::at(self::NOW), null), 'GetItem');
    }

    public function testMarkReadyRecordsTheEtagOfTheInspectedBytes(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem(self::readyFields())]));

        $this->repository()->markReady(self::keyOf(), ReceiptContentType::Png, self::at(self::NOW), 'abc123');

        $command = $this->commandBody(0);
        self::assertSame(
            'SET #status = :ready, #contentType = :detected, #processedAt = :processedAt, #objectEtag = :etag REMOVE #expiresAt, #failureReason',
            $command['UpdateExpression'],
        );
        $names = $this->commands[0]->toArray()['ExpressionAttributeNames'] ?? null;
        self::assertIsArray($names);
        self::assertSame('objectEtag', $names['#objectEtag'] ?? null);
        self::assertSame('abc123', self::valueOf($command, ':etag'));
        self::assertSame('#objectKey = :key AND #status = :pending AND #expiresAt > :now', $command['ConditionExpression']);
    }

    // markReplaced

    public function testMarkReplacedFailsAReadyRecordOnlyWhileItsEtagIsTheStoredOne(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem(self::readyFields())]));
        $now = new DateTimeImmutable('2026-10-29T14:00:00+02:00');

        $this->repository()->markReplaced(self::keyOf(), 'abc123', FailureReason::Unreadable, $now);

        $this->assertCommandNames('UpdateItem');
        $command = $this->commandBody(0);
        self::assertSame(['PK' => 'USER#' . self::USER, 'SK' => 'RCPT#' . self::EXPENSE], $command['Key'] ?? null);
        self::assertSame('#objectKey = :key AND #status = :ready AND #objectEtag = :stored', $command['ConditionExpression']);
        self::assertSame(
            'SET #status = :failed, #failureReason = :reason, #expiresAt = :expiresAt, #processedAt = :processedAt',
            $command['UpdateExpression'],
        );
        self::assertSame(self::keyOf()->originalKey(), self::valueOf($command, ':key'));
        self::assertSame('ready', self::valueOf($command, ':ready'));
        self::assertSame('abc123', self::valueOf($command, ':stored'));
        self::assertSame('failed', self::valueOf($command, ':failed'));
        self::assertSame('unreadable', self::valueOf($command, ':reason'));
        self::assertSame((string) ($now->getTimestamp() + 604_800), self::valueOf($command, ':expiresAt'));
        self::assertSame('2026-10-29T12:00:00Z', self::valueOf($command, ':processedAt'));
    }

    public function testMarkReplacedWhoseConditionFailsIsNotPendingAndWritesNothingElse(): void
    {
        $this->mock->append(self::conditionFailed());

        try {
            $this->repository()->markReplaced(self::keyOf(), 'abc123', FailureReason::Unreadable, self::at(self::NOW));
            self::fail('The caller must re-read the record.');
        } catch (ReceiptNotPending) {
            $this->assertCommandNames('UpdateItem');
        }
    }

    public function testMarkReplacedRethrowsAStorageFailure(): void
    {
        $this->mock->append(new DynamoDbException('denied', new \Aws\Command('UpdateItem'), ['code' => 'AccessDeniedException']));

        $this->expectException(StorageFailure::class);

        $this->repository()->markReplaced(self::keyOf(), 'abc123', FailureReason::Unreadable, self::at(self::NOW));
    }

    public function testFindByKeyHydratesTheEtagOfASettledRecord(): void
    {
        $this->mock->append(self::stored(self::readyFields(['objectEtag' => 'abc123'])), self::stored(self::readyFields()));

        $with = $this->repository()->findByKey(self::keyOf());
        $without = $this->repository()->findByKey(self::keyOf());

        self::assertNotNull($with);
        self::assertNotNull($without);
        self::assertSame('abc123', $with->objectEtag);
        self::assertNull($without->objectEtag);
    }

    // markFailed

    public function testMarkFailedRecordsTheEtagWhenThereIsOne(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem()]));

        $this->repository()->markFailed(self::keyOf(), FailureReason::Unreadable, self::at(self::NOW), 'abc123');

        $command = $this->commandBody(0);
        self::assertSame(
            'SET #status = :failed, #failureReason = :reason, #expiresAt = :expiresAt, #processedAt = :processedAt, #objectEtag = :etag',
            $command['UpdateExpression'],
        );
        self::assertSame('abc123', self::valueOf($command, ':etag'));
    }

    public function testMarkFailedSendsTheConditionalUpdateWithTheFailedLifetime(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem()]));
        $now = new DateTimeImmutable('2026-10-29T14:00:00+02:00');

        $result = $this->repository()->markFailed(self::keyOf(), FailureReason::TooLarge, $now, null);

        self::assertSame(SettleResult::Applied, $result);
        $this->assertCommandNames('UpdateItem');
        $command = $this->commandBody(0);
        self::assertSame('#objectKey = :key AND #status = :pending AND #expiresAt > :now', $command['ConditionExpression']);
        self::assertSame((string) $now->getTimestamp(), self::valueOf($command, ':now'));
        self::assertSame((string) ($now->getTimestamp() + 604_800), self::valueOf($command, ':expiresAt'));
        self::assertSame('too-large', self::valueOf($command, ':reason'));
        self::assertSame('failed', self::valueOf($command, ':failed'));
        self::assertSame('2026-10-29T12:00:00Z', self::valueOf($command, ':processedAt'));
        self::assertSame(
            'SET #status = :failed, #failureReason = :reason, #expiresAt = :expiresAt, #processedAt = :processedAt',
            $command['UpdateExpression'],
        );
    }

    public function testMarkFailedAfterTheRecordWasSettledReportsAlreadyProcessed(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored(self::readyFields()));

        self::assertSame(SettleResult::AlreadyProcessed, $this->repository()->markFailed(self::keyOf(), FailureReason::Unreadable, self::at(self::NOW), null));
    }

    public function testMarkFailedForAnotherKeyIsNotPending(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored(['receiptId' => self::NEW_RECEIPT, 'objectKey' => self::keyOf(self::NEW_RECEIPT)->originalKey()]));

        $this->expectException(ReceiptNotPending::class);

        $this->repository()->markFailed(self::keyOf(), FailureReason::Unreadable, self::at(self::NOW), null);
    }

    public function testMarkFailedOfAnExpiredPendingRecordIsNotPending(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored());

        $this->expectException(ReceiptNotPending::class);

        $this->repository()->markFailed(self::keyOf(), FailureReason::Unreadable, self::at(self::EXPIRES), null);
    }
}
