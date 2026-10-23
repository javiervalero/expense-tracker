<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses;

use App\Auth\Session\Principal;
use App\Expenses\Amount;
use App\Expenses\Description;
use App\Expenses\Expense;
use App\Expenses\ExpenseDate;
use App\Expenses\ExpenseNotFound;
use App\Expenses\ExpenseRepository;
use App\Expenses\InvalidStartKey;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StaleVersion;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use Aws\CommandInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use DateTimeImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The branches of ExpenseRepository that need a scripted DynamoDB answer (the exact request that goes out,
 * cancellation reasons by position, overload, items of another partition); the real behaviour is in the integration
 * test. Ids, descriptions and times are invented.
 */
final class ExpenseRepositoryTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_USER = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const LUNCH_ID = '5c8e1f3a-2b4d-4a6c-8e0f-1a2b3c4d5e6f';
    private const CATEGORY_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const NOW = '2026-10-23T10:00:00+00:00';
    private const LATER = '2026-10-24T11:00:00+00:00';

    /** The attribute-name placeholders of both update expressions (every one is used in both). */
    private const UPDATE_NAMES = ['#amountCents', '#categoryId', '#date', '#description', '#lsi1sk', '#updatedAt', '#version'];

    private MockHandler $mock;

    /** @var list<CommandInterface> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->commands = [];
        $this->mock = new MockHandler();
    }

    private function repository(): ExpenseRepository
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;

            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };

        return new ExpenseRepository(new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName));
    }

    private static function owner(): Principal
    {
        return new Principal(self::USER, str_repeat('a', 64));
    }

    private static function lunch(?string $categoryId = self::CATEGORY_ID): Expense
    {
        $date = ExpenseDate::parse('2026-10-20');
        $amount = Amount::parse(1250);
        $description = Description::parse('Lunch');
        self::assertInstanceOf(ExpenseDate::class, $date);
        self::assertInstanceOf(Amount::class, $amount);
        self::assertInstanceOf(Description::class, $description);

        return Expense::new(self::LUNCH_ID, $date, $amount, $description, $categoryId, new DateTimeImmutable(self::NOW));
    }

    private static function coffee(?string $categoryId = null): Expense
    {
        $date = ExpenseDate::parse('2026-10-21');
        $amount = Amount::parse(900);
        $description = Description::parse('Coffee');
        self::assertInstanceOf(ExpenseDate::class, $date);
        self::assertInstanceOf(Amount::class, $amount);
        self::assertInstanceOf(Description::class, $description);

        return self::lunch()->updated($date, $amount, $description, $categoryId, new DateTimeImmutable(self::LATER));
    }

    /**
     * @param list<string> $codes
     */
    private static function cancelled(array $codes): DynamoDbException
    {
        $reasons = array_map(static fn(string $code): array => ['Code' => $code], $codes);

        return new DynamoDbException('cancelled', new \Aws\Command('TransactWriteItems'), ['code' => 'TransactionCanceledException', 'body' => ['CancellationReasons' => $reasons]]);
    }

    private static function conditionFailed(): DynamoDbException
    {
        return new DynamoDbException('failed', new \Aws\Command('PutItem'), ['code' => 'ConditionalCheckFailedException']);
    }

    private static function refused(): DynamoDbException
    {
        return new DynamoDbException('denied', new \Aws\Command('UpdateItem'), ['code' => 'AccessDeniedException']);
    }

    /**
     * The raw (typed) attributes of a stored expense.
     *
     * @param array<string, string|int|float|null> $override a null value removes the attribute
     *
     * @return array<string, array<string, string>>
     */
    private static function rawItem(array $override = []): array
    {
        $fields = $override + [
            'PK' => 'USER#' . self::USER,
            'SK' => 'EXP#' . self::LUNCH_ID,
            'LSI1SK' => 'EXP#2026-10-20#' . self::LUNCH_ID,
            'expenseId' => self::LUNCH_ID,
            'date' => '2026-10-20',
            'amountCents' => 1250,
            'description' => 'Lunch',
            'categoryId' => self::CATEGORY_ID,
            'version' => 1,
            'createdAt' => '2026-10-23T10:00:00Z',
            'updatedAt' => '2026-10-23T10:00:00Z',
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
     * @param array<string, string|int|float|null> $override
     *
     * @return Result<string, mixed>
     */
    private static function stored(array $override = []): Result
    {
        return new Result(['Item' => self::rawItem($override)]);
    }

    /**
     * The sorted attribute names of the item put or updated by the nth recorded command.
     */
    private function commandField(int $index, string $field): mixed
    {
        return $this->commands[$index][$field] ?? null;
    }

    /**
     * @return list<string>
     */
    private function sortedKeysOf(string $field): array
    {
        $map = $this->commandField(0, $field);
        self::assertIsArray($map);
        $keys = array_map(strval(...), array_keys($map));
        sort($keys);

        return $keys;
    }

    private function assertCommandNames(string ...$names): void
    {
        self::assertSame($names, array_map(static fn(CommandInterface $command): string => $command->getName(), $this->commands));
    }

    // create

    public function testCreateSendsOneConditionalPutOfTheFieldByFieldItem(): void
    {
        $this->mock->append(new Result([]));

        $this->repository()->create(self::owner(), self::lunch());

        $this->assertCommandNames('PutItem');
        self::assertSame('attribute_not_exists(PK)', $this->commandField(0, 'ConditionExpression'));
        $item = $this->commandField(0, 'Item');
        self::assertIsArray($item);
        $keys = array_keys($item);
        sort($keys);
        self::assertSame(['LSI1SK', 'PK', 'SK', 'amountCents', 'categoryId', 'createdAt', 'date', 'description', 'expenseId', 'updatedAt', 'version'], $keys);
        self::assertSame(['N' => '1250'], $item['amountCents']);
        self::assertSame(['S' => 'EXP#2026-10-20#' . self::LUNCH_ID], $item['LSI1SK']);
        self::assertSame(['S' => '2026-10-23T10:00:00Z'], $item['createdAt']);
    }

    public function testCreateWithoutACategorySendsNoCategoryIdAttribute(): void
    {
        $this->mock->append(new Result([]));

        $this->repository()->create(self::owner(), self::lunch(null));

        $item = $this->commandField(0, 'Item');
        self::assertIsArray($item);
        self::assertArrayNotHasKey('categoryId', $item);
    }

    public function testCreateWhoseConditionFailedIsSuccessWhenTheStoredItemEqualsOurs(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored());

        $this->repository()->create(self::owner(), self::lunch());

        $this->assertCommandNames('PutItem', 'GetItem');
    }

    /**
     * @param array<string, string|int|null> $override
     */
    #[DataProvider('storedItemsThatAreNotOurCreate')]
    public function testCreateWhoseConditionFailedIsReportedWhenTheStoredItemIsNotOurs(array $override): void
    {
        $this->mock->append(self::conditionFailed(), self::stored($override));

        $this->expectException(ConditionFailed::class);

        $this->repository()->create(self::owner(), self::lunch());
    }

    /**
     * @return array<string, array{array<string, string|int|null>}>
     */
    public static function storedItemsThatAreNotOurCreate(): array
    {
        return [
            'another amount' => [['amountCents' => 1251]],
            'another description' => [['description' => 'Dinner']],
            'another date' => [['date' => '2026-10-19', 'LSI1SK' => 'EXP#2026-10-19#' . self::LUNCH_ID]],
            'another category' => [['categoryId' => null]],
            'another version' => [['version' => 2]],
            'another creation time' => [['createdAt' => '2026-10-23T10:00:01Z']],
            'another update time' => [['updatedAt' => '2026-10-23T10:00:01Z']],
        ];
    }

    public function testCreateWhoseConditionFailedIsReportedWhenTheItemIsGoneAtTheReread(): void
    {
        $this->mock->append(self::conditionFailed(), new Result([]));

        $this->expectException(ConditionFailed::class);

        $this->repository()->create(self::owner(), self::lunch());
    }

    public function testCreateRethrowsAStorageFailureUnchanged(): void
    {
        $this->mock->append(self::refused());

        $this->expectException(StorageFailure::class);

        $this->repository()->create(self::owner(), self::lunch());
    }

    // update

    public function testUpdateWithACategorySetsEveryFieldInOneConditionalUpdate(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem(['version' => 2, 'amountCents' => 900, 'description' => 'Coffee', 'date' => '2026-10-21', 'LSI1SK' => 'EXP#2026-10-21#' . self::LUNCH_ID, 'updatedAt' => '2026-10-24T11:00:00Z'])]));

        $returned = $this->repository()->update(self::owner(), self::coffee(self::CATEGORY_ID), 1);

        $this->assertCommandNames('UpdateItem');
        $expression = $this->commandField(0, 'UpdateExpression');
        self::assertIsString($expression);
        self::assertSame('SET #date = :date, #lsi1sk = :lsi1sk, #amountCents = :amountCents, #description = :description, #categoryId = :categoryId, #version = :next, #updatedAt = :updatedAt', $expression);
        self::assertSame(self::UPDATE_NAMES, $this->sortedKeysOf('ExpressionAttributeNames'));
        self::assertSame([':amountCents', ':categoryId', ':date', ':description', ':expected', ':lsi1sk', ':next', ':updatedAt'], $this->sortedKeysOf('ExpressionAttributeValues'));
        self::assertSame('attribute_exists(PK) AND #version = :expected', $this->commandField(0, 'ConditionExpression'));
        $values = $this->commandField(0, 'ExpressionAttributeValues');
        self::assertIsArray($values);
        self::assertSame(['N' => '1'], $values[':expected']);
        self::assertSame(['N' => '2'], $values[':next']);
        self::assertSame(['S' => self::CATEGORY_ID], $values[':categoryId']);
        self::assertSame(['S' => 'EXP#2026-10-21#' . self::LUNCH_ID], $values[':lsi1sk']);
        self::assertSame(2, $returned->version);
        self::assertSame('ALL_NEW', $this->commandField(0, 'ReturnValues'));
    }

    public function testUpdateWithoutACategoryRemovesTheAttributeAndSendsNoCategoryValue(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem(['version' => 2, 'categoryId' => null, 'amountCents' => 900, 'description' => 'Coffee', 'date' => '2026-10-21', 'LSI1SK' => 'EXP#2026-10-21#' . self::LUNCH_ID, 'updatedAt' => '2026-10-24T11:00:00Z'])]));

        $this->repository()->update(self::owner(), self::coffee(null), 1);

        $expression = $this->commandField(0, 'UpdateExpression');
        self::assertIsString($expression);
        self::assertSame('SET #date = :date, #lsi1sk = :lsi1sk, #amountCents = :amountCents, #description = :description, #version = :next, #updatedAt = :updatedAt REMOVE #categoryId', $expression);
        self::assertSame(self::UPDATE_NAMES, $this->sortedKeysOf('ExpressionAttributeNames'));
        self::assertSame([':amountCents', ':date', ':description', ':expected', ':lsi1sk', ':next', ':updatedAt'], $this->sortedKeysOf('ExpressionAttributeValues'));
    }

    public function testUpdateRefusesAnExpenseThatIsNotTheNextVersionBeforeAnyRequest(): void
    {
        try {
            $this->repository()->update(self::owner(), self::coffee(), 2);
            self::fail('A version that does not follow the expected one must be refused.');
        } catch (\InvalidArgumentException) {
            $this->assertCommandNames();
        }
    }

    public function testUpdateWhoseConditionFailedIsSuccessWhenTheStoredItemHoldsExactlyWhatWeWrote(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored(['version' => 2, 'categoryId' => null, 'amountCents' => 900, 'description' => 'Coffee', 'date' => '2026-10-21', 'LSI1SK' => 'EXP#2026-10-21#' . self::LUNCH_ID, 'updatedAt' => '2026-10-24T11:00:00Z']));

        $returned = $this->repository()->update(self::owner(), self::coffee(), 1);

        $this->assertCommandNames('UpdateItem', 'GetItem');
        self::assertSame(2, $returned->version);
        self::assertSame('Coffee', $returned->description->value());
    }

    public function testUpdateWhoseConditionFailedIsNotFoundWhenTheItemIsGone(): void
    {
        $this->mock->append(self::conditionFailed(), new Result([]));

        $this->expectException(ExpenseNotFound::class);

        $this->repository()->update(self::owner(), self::coffee(), 1);
    }

    public function testUpdateWhoseConditionFailedIsStaleWhenTheStoredVersionIsNotTheNextOne(): void
    {
        $this->mock->append(self::conditionFailed(), self::stored(['version' => 5]));

        $this->expectException(StaleVersion::class);

        $this->repository()->update(self::owner(), self::coffee(), 1);
    }

    public function testUpdateRethrowsAStorageFailureUnchangedAndNeverReReadsIt(): void
    {
        $this->mock->append(self::refused());

        try {
            $this->repository()->update(self::owner(), self::coffee(), 1);
            self::fail('A storage failure must surface.');
        } catch (StorageFailure $failure) {
            self::assertSame('UpdateItem', $failure->operation);
            $this->assertCommandNames('UpdateItem');
        }
    }

    public function testUpdateWhoseAnswerIsACorruptItemNamesUpdateItem(): void
    {
        $this->mock->append(new Result(['Attributes' => self::rawItem(['version' => 0])]));

        try {
            $this->repository()->update(self::owner(), self::coffee(), 1);
            self::fail('A corrupt answer must not be returned.');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
            self::assertSame('UpdateItem', $failure->operation);
        }
    }

    // delete

    public function testDeleteSendsTheExpenseWithAnExistsConditionAndTheReceiptRecordUnconditionally(): void
    {
        $this->mock->append(new Result([]));

        $this->repository()->delete(self::owner(), self::LUNCH_ID);

        $this->assertCommandNames('TransactWriteItems');
        /** @var list<array{Delete: array{Key: array{PK: array{S: string}, SK: array{S: string}}, ConditionExpression?: string}}> $items */
        $items = $this->commandField(0, 'TransactItems');
        self::assertCount(2, $items);
        self::assertSame('attribute_exists(PK)', $items[0]['Delete']['ConditionExpression'] ?? null);
        self::assertSame(['S' => 'EXP#' . self::LUNCH_ID], $items[0]['Delete']['Key']['SK']);
        self::assertSame(['S' => 'USER#' . self::USER], $items[0]['Delete']['Key']['PK']);
        self::assertSame(['S' => 'RCPT#' . self::LUNCH_ID], $items[1]['Delete']['Key']['SK']);
        self::assertSame(['S' => 'USER#' . self::USER], $items[1]['Delete']['Key']['PK']);
        self::assertArrayNotHasKey('ConditionExpression', $items[1]['Delete']);
    }

    public function testDeleteMapsAFailedExpenseConditionByPositionToNotFound(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'None']));

        $this->expectException(ExpenseNotFound::class);

        $this->repository()->delete(self::owner(), self::LUNCH_ID);
    }

    /**
     * @param list<string> $reasons
     */
    #[DataProvider('cancellationsThatAreNotAMissingExpense')]
    public function testDeleteRethrowsAnyOtherCancellationUnchanged(array $reasons): void
    {
        $this->mock->append(self::cancelled($reasons));

        try {
            $this->repository()->delete(self::owner(), self::LUNCH_ID);
            self::fail('Overload must surface as the cancellation.');
        } catch (TransactionCancelled $cancelled) {
            self::assertSame($reasons, $cancelled->reasons);
            $this->assertCommandNames('TransactWriteItems');
        }
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function cancellationsThatAreNotAMissingExpense(): array
    {
        return [
            'conflict on the expense' => [['TransactionConflict', 'None']],
            'throttled' => [['ThrottlingError', 'ThrottlingError']],
            'failed condition only at the receipt position' => [['None', 'ConditionalCheckFailed']],
            'no reasons' => [[]],
        ];
    }

    // pageByDate

    public function testPageByDateSendsADescendingConsistentIndexQueryInThePrincipalsPartition(): void
    {
        $this->mock->append(new Result(['Items' => []]));
        $from = ExpenseDate::parse('2026-10-01');
        $to = ExpenseDate::parse('2026-10-31');
        self::assertInstanceOf(ExpenseDate::class, $from);
        self::assertInstanceOf(ExpenseDate::class, $to);

        $this->repository()->pageByDate(self::owner(), $from, $to, null, 25);

        $this->assertCommandNames('Query');
        self::assertSame('LSI1', $this->commandField(0, 'IndexName'));
        self::assertFalse($this->commandField(0, 'ScanIndexForward'));
        self::assertTrue($this->commandField(0, 'ConsistentRead'));
        self::assertSame(25, $this->commandField(0, 'Limit'));
        self::assertSame('#pk = :pk AND LSI1SK BETWEEN :from AND :to', $this->commandField(0, 'KeyConditionExpression'));
        $values = $this->commandField(0, 'ExpressionAttributeValues');
        self::assertIsArray($values);
        self::assertSame(['S' => 'USER#' . self::USER], $values[':pk']);
        self::assertSame(['S' => 'EXP#2026-10-01'], $values[':from']);
        self::assertSame(['S' => 'EXP#2026-10-31~'], $values[':to']);
        self::assertNull($this->commandField(0, 'ExclusiveStartKey'));
    }

    /**
     * @param array<string, string> $startKey
     */
    #[DataProvider('refusedStartKeys')]
    public function testPageByDateRefusesAStartKeyThatIsNotAPositionInTheRangeWithoutAnyRequest(array $startKey): void
    {
        $from = ExpenseDate::parse('2026-10-01');
        $to = ExpenseDate::parse('2026-10-31');
        self::assertInstanceOf(ExpenseDate::class, $from);
        self::assertInstanceOf(ExpenseDate::class, $to);

        try {
            $this->repository()->pageByDate(self::owner(), $from, $to, $startKey, 10);
            self::fail('A bad start key must be refused.');
        } catch (InvalidStartKey $refused) {
            self::assertSame('The start key is not an expense position in the range.', $refused->getMessage());
            $this->assertCommandNames();
        }
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function refusedStartKeys(): array
    {
        $id = self::LUNCH_ID;

        return [
            'dated before from' => [['SK' => 'EXP#' . $id, 'LSI1SK' => 'EXP#2026-09-30#' . $id]],
            'dated after to' => [['SK' => 'EXP#' . $id, 'LSI1SK' => 'EXP#2026-11-01#' . $id]],
            'another kind of sort key' => [['SK' => 'PROFILE', 'LSI1SK' => 'EXP#2026-10-20#' . $id]],
            'a bad id' => [['SK' => 'EXP#NOT-A-UUID', 'LSI1SK' => 'EXP#2026-10-20#NOT-A-UUID']],
            'an index key of another id' => [['SK' => 'EXP#' . $id, 'LSI1SK' => 'EXP#2026-10-20#' . self::CATEGORY_ID]],
            'an extra member' => [['PK' => 'USER#' . self::OTHER_USER, 'SK' => 'EXP#' . $id, 'LSI1SK' => 'EXP#2026-10-20#' . $id]],
        ];
    }

    public function testPageByDateTakesThePartitionOfTheStartKeyFromThePrincipalOnly(): void
    {
        $this->mock->append(new Result(['Items' => []]));
        $from = ExpenseDate::min();
        $to = ExpenseDate::max();

        $this->repository()->pageByDate(self::owner(), $from, $to, ['SK' => 'EXP#' . self::LUNCH_ID, 'LSI1SK' => 'EXP#2026-10-20#' . self::LUNCH_ID], 10);

        self::assertSame([
            'PK' => ['S' => 'USER#' . self::USER],
            'SK' => ['S' => 'EXP#' . self::LUNCH_ID],
            'LSI1SK' => ['S' => 'EXP#2026-10-20#' . self::LUNCH_ID],
        ], $this->commandField(0, 'ExclusiveStartKey'));
    }

    public function testPageByDateReturnsTheLastKeyWithoutThePartition(): void
    {
        $this->mock->append(new Result([
            'Items' => [self::rawItem()],
            'LastEvaluatedKey' => ['PK' => ['S' => 'USER#' . self::USER], 'SK' => ['S' => 'EXP#' . self::LUNCH_ID], 'LSI1SK' => ['S' => 'EXP#2026-10-20#' . self::LUNCH_ID]],
        ]));

        $batch = $this->repository()->pageByDate(self::owner(), ExpenseDate::min(), ExpenseDate::max(), null, 1);

        self::assertCount(1, $batch->items);
        self::assertSame(['SK' => 'EXP#' . self::LUNCH_ID, 'LSI1SK' => 'EXP#2026-10-20#' . self::LUNCH_ID], $batch->lastKey);
    }

    /**
     * @param array<string, array<string, string>> $lastKey
     */
    #[DataProvider('lastKeysThatAreNotOurs')]
    public function testPageByDateWithAForeignOrMalformedLastKeyIsACorruptItemFailureNamingTheQuery(array $lastKey): void
    {
        $this->mock->append(new Result(['Items' => [], 'LastEvaluatedKey' => $lastKey]));

        try {
            $this->repository()->pageByDate(self::owner(), ExpenseDate::parse('2026-10-01') ?? self::fail('date'), ExpenseDate::parse('2026-10-31') ?? self::fail('date'), null, 1);
            self::fail('A foreign last key must not be handed out.');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
            self::assertSame('Query', $failure->operation);
        }
    }

    /**
     * @return array<string, array{array<string, array<string, string>>}>
     */
    public static function lastKeysThatAreNotOurs(): array
    {
        $sk = ['S' => 'EXP#' . self::LUNCH_ID];
        $lsi = ['S' => 'EXP#2026-10-20#' . self::LUNCH_ID];

        return [
            'another partition' => [['PK' => ['S' => 'USER#' . self::OTHER_USER], 'SK' => $sk, 'LSI1SK' => $lsi]],
            'no partition' => [['SK' => $sk, 'LSI1SK' => $lsi]],
            'sort key is a number' => [['PK' => ['S' => 'USER#' . self::USER], 'SK' => ['N' => '5'], 'LSI1SK' => $lsi]],
            'index key is a number' => [['PK' => ['S' => 'USER#' . self::USER], 'SK' => $sk, 'LSI1SK' => ['N' => '5']]],
            'sort key of another kind' => [['PK' => ['S' => 'USER#' . self::USER], 'SK' => ['S' => 'PROFILE'], 'LSI1SK' => $lsi]],
            'index key of another id' => [['PK' => ['S' => 'USER#' . self::USER], 'SK' => $sk, 'LSI1SK' => ['S' => 'EXP#2026-10-20#' . self::CATEGORY_ID]]],
            'index key with a bad date' => [['PK' => ['S' => 'USER#' . self::USER], 'SK' => $sk, 'LSI1SK' => ['S' => 'EXP#2026-13-45#' . self::LUNCH_ID]]],
            'an extra member' => [['PK' => ['S' => 'USER#' . self::USER], 'SK' => $sk, 'LSI1SK' => $lsi, 'version' => ['N' => '1']]],
            'well formed but dated after the range' => [['PK' => ['S' => 'USER#' . self::USER], 'SK' => $sk, 'LSI1SK' => ['S' => 'EXP#2026-11-01#' . self::LUNCH_ID]]],
            'well formed but dated before the range' => [['PK' => ['S' => 'USER#' . self::USER], 'SK' => $sk, 'LSI1SK' => ['S' => 'EXP#2026-09-30#' . self::LUNCH_ID]]],
            'no index key' => [['PK' => ['S' => 'USER#' . self::USER], 'SK' => $sk]],
        ];
    }

    public function testAnItemOfAnotherPartitionInAnAnswerIsCorruptNamingTheOperationThatReturnedIt(): void
    {
        $foreign = ['PK' => 'USER#' . self::OTHER_USER];
        $this->mock->append(self::stored($foreign), new Result(['Items' => [self::rawItem($foreign)]]));

        foreach (['GetItem' => fn() => $this->repository()->find(self::owner(), self::LUNCH_ID), 'Query' => fn() => $this->repository()->pageByDate(self::owner(), ExpenseDate::min(), ExpenseDate::max(), null, 5)] as $operation => $call) {
            try {
                $call();
                self::fail("A foreign item from $operation must not be read.");
            } catch (StorageFailure $failure) {
                self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
                self::assertSame($operation, $failure->operation);
            }
        }
    }
}
