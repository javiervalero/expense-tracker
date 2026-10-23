<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryName;
use App\Categories\CategoryNameTaken;
use App\Categories\CategoryNotFound;
use App\Categories\CategoryRepository;
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
 * The branches of CategoryRepository that need a scripted DynamoDB answer (cancellation reasons by position, retries,
 * overload); the real behaviour is in the integration test. Ids, names and times are invented.
 */
final class CategoryRepositoryTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const OTHER_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const NOW = '2026-10-23T10:00:00+00:00';
    private const LATER = '2026-10-23T12:00:00+00:00';

    private MockHandler $mock;

    /** @var list<CommandInterface> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->commands = [];
        $this->mock = new MockHandler();
    }

    private function repository(): CategoryRepository
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;

            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };

        return new CategoryRepository(new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName));
    }

    private static function owner(): Principal
    {
        return new Principal(self::USER, str_repeat('a', 64));
    }

    private static function nameOf(string $text): CategoryName
    {
        $name = CategoryName::parse($text);
        self::assertInstanceOf(CategoryName::class, $name);

        return $name;
    }

    private static function food(): Category
    {
        return Category::new(self::FOOD_ID, self::nameOf('Food'), new DateTimeImmutable(self::NOW));
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
        return new DynamoDbException('failed', new \Aws\Command('DeleteItem'), ['code' => 'ConditionalCheckFailedException']);
    }

    /**
     * @return Result<string, mixed>
     */
    private static function lockHeldBy(string $holder): Result
    {
        return new Result(['Item' => ['PK' => ['S' => 'USER#' . self::USER], 'SK' => ['S' => 'CATNAME#' . self::food()->nameKey], 'categoryId' => ['S' => $holder]]]);
    }

    /**
     * @param array<string, string|int|null> $override a null value removes the attribute
     *
     * @return Result<string, mixed>
     */
    private static function storedCategory(array $override = []): Result
    {
        $fields = $override + [
            'PK' => 'USER#' . self::USER,
            'SK' => 'CAT#' . self::FOOD_ID,
            'categoryId' => self::FOOD_ID,
            'name' => 'Food',
            'nameKey' => self::food()->nameKey,
            'version' => 1,
            'createdAt' => '2026-10-23T10:00:00Z',
            'updatedAt' => '2026-10-23T10:00:00Z',
        ];
        $raw = [];
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $raw[$name] = is_int($value) ? ['N' => (string) $value] : ['S' => $value];
            }
        }

        return new Result(['Item' => $raw]);
    }

    /**
     * The string at a path inside a recorded command or one of its transaction items, or null.
     */
    private static function dig(mixed $value, string ...$path): ?string
    {
        foreach ($path as $segment) {
            if (!is_array($value) && !$value instanceof \ArrayAccess) {
                return null;
            }
            $value = $value[$segment] ?? null;
        }

        return is_string($value) ? $value : null;
    }

    private function assertCommandNames(string ...$names): void
    {
        self::assertSame($names, array_map(static fn(CommandInterface $command): string => $command->getName(), $this->commands));
    }

    // create

    public function testCreateSendsOneTransactionWithTheCategoryAndTheLockEachConditional(): void
    {
        $this->mock->append(new Result([]));
        $food = self::food();

        $this->repository()->create(self::owner(), $food);

        $this->assertCommandNames('TransactWriteItems');
        /** @var list<array{Put: array{ConditionExpression: string, Item: array<string, array{S?: string, N?: string}>}}> $items */
        $items = $this->commands[0]['TransactItems'];
        self::assertCount(2, $items);
        foreach ($items as $item) {
            self::assertSame('attribute_not_exists(PK)', $item['Put']['ConditionExpression']);
        }
        self::assertSame('CAT#' . self::FOOD_ID, $items[0]['Put']['Item']['SK']['S'] ?? null);
        self::assertSame($food->nameKey, $items[0]['Put']['Item']['nameKey']['S'] ?? null);
        self::assertSame('1', $items[0]['Put']['Item']['version']['N'] ?? null);
        self::assertSame('CATNAME#' . $food->nameKey, self::dig($items[1], 'Put', 'Item', 'SK', 'S'));
        self::assertSame(self::FOOD_ID, $items[1]['Put']['Item']['categoryId']['S'] ?? null);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function overloadOrNoConditionFailure(): iterable
    {
        yield 'throttling on both' => [['ThrottlingError', 'ThrottlingError']];
        yield 'a transaction conflict on both items' => [['TransactionConflict', 'TransactionConflict']];
        yield 'a transaction conflict on the lock' => [['None', 'TransactionConflict']];
        yield 'a conflict and a throttle' => [['TransactionConflict', 'ProvisionedThroughputExceeded']];
        yield 'a validation error' => [['ValidationError', 'None']];
        yield 'no reasons at all' => [[]];
    }

    /**
     * @param list<string> $reasons
     */
    #[DataProvider('overloadOrNoConditionFailure')]
    public function testCreateRethrowsACancellationThatIsNotAFailedConditionUnchanged(array $reasons): void
    {
        $this->mock->append(self::cancelled($reasons));

        try {
            $this->repository()->create(self::owner(), self::food());
            self::fail('TransactionCancelled was expected.');
        } catch (TransactionCancelled $cancelled) {
            self::assertSame($reasons, $cancelled->reasons);
        }
        $this->assertCommandNames('TransactWriteItems');
    }

    public function testCreateRethrowsAFailedCategoryConditionWhenTheLockIsNotInvolved(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'None']));

        try {
            $this->repository()->create(self::owner(), self::food());
            self::fail('TransactionCancelled was expected.');
        } catch (TransactionCancelled $cancelled) {
            self::assertSame(['ConditionalCheckFailed', 'None'], $cancelled->reasons);
        }
        $this->assertCommandNames('TransactWriteItems');
    }

    public function testCreateTreatsALockHoldingItsOwnIdAsASuccess(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'ConditionalCheckFailed']));
        $this->mock->append(self::lockHeldBy(self::FOOD_ID));

        $this->repository()->create(self::owner(), self::food());

        $this->assertCommandNames('TransactWriteItems', 'GetItem');
    }

    public function testCreateReportsANameTakenOnlyWhenTheHolderStillExists(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(self::lockHeldBy(self::OTHER_ID));
        $this->mock->append(self::storedCategory(['categoryId' => self::OTHER_ID, 'SK' => 'CAT#' . self::OTHER_ID]));

        try {
            $this->repository()->create(self::owner(), self::food());
            self::fail('CategoryNameTaken was expected.');
        } catch (CategoryNameTaken) {
            // expected
        }
        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'GetItem');
    }

    public function testCreateRemovesAStaleLockConditionallyOnTheHoldersIdAndTriesOnceMore(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(self::lockHeldBy(self::OTHER_ID));
        $this->mock->append(new Result([]));
        $this->mock->append(new Result([]));
        $this->mock->append(new Result([]));

        $this->repository()->create(self::owner(), self::food());

        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'GetItem', 'DeleteItem', 'TransactWriteItems');
        self::assertSame('#categoryId = :stale', $this->commands[3]['ConditionExpression']);
        self::assertSame(self::OTHER_ID, self::dig($this->commands[3], 'ExpressionAttributeValues', ':stale', 'S'));
        self::assertSame('CATNAME#' . self::food()->nameKey, self::dig($this->commands[3], 'Key', 'SK', 'S'));
    }

    public function testCreateGivesUpAfterOneRepairWhenTheLockIsStaleAgain(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(self::lockHeldBy(self::OTHER_ID));
        $this->mock->append(new Result([]));
        $this->mock->append(new Result([]));
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(self::lockHeldBy(self::OTHER_ID));
        $this->mock->append(new Result([]));

        try {
            $this->repository()->create(self::owner(), self::food());
            self::fail('TransactionCancelled was expected.');
        } catch (TransactionCancelled $cancelled) {
            self::assertSame(['None', 'ConditionalCheckFailed'], $cancelled->reasons);
        }
        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'GetItem', 'DeleteItem', 'TransactWriteItems', 'GetItem', 'GetItem');
    }

    public function testCreateIgnoresALostRaceOnTheStaleLockRepairAndTriesAgain(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(self::lockHeldBy(self::OTHER_ID));
        $this->mock->append(new Result([]));
        $this->mock->append(self::conditionFailed());
        $this->mock->append(new Result([]));

        $this->repository()->create(self::owner(), self::food());

        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'GetItem', 'DeleteItem', 'TransactWriteItems');
    }

    public function testCreateTriesAgainOnceWhenTheLockWasReleasedInTheMeantimeAndThenGivesUp(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(new Result([]));
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(new Result([]));

        $this->expectException(TransactionCancelled::class);

        try {
            $this->repository()->create(self::owner(), self::food());
        } finally {
            $this->assertCommandNames('TransactWriteItems', 'GetItem', 'TransactWriteItems', 'GetItem');
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, array<string, string>|null}>
     */
    public static function malformedLocks(): iterable
    {
        yield 'a categoryId that is not a uuid' => [['categoryId' => ['S' => 'food']], '#categoryId = :stale', ['S' => 'food']];
        yield 'a categoryId of the wrong type' => [['categoryId' => ['N' => '7']], 'NOT attribute_type(#categoryId, :type)', ['S' => 'S']];
        yield 'a map as categoryId' => [['categoryId' => ['M' => ['a' => ['S' => 'x']]]], 'NOT attribute_type(#categoryId, :type)', ['S' => 'S']];
        yield 'no categoryId' => [['PK' => ['S' => 'x']], 'attribute_not_exists(#categoryId)', null];
    }

    /**
     * @param array<string, mixed> $lock
     * @param array<string, string>|null $expectedValue
     */
    #[DataProvider('malformedLocks')]
    public function testCreateTreatsAMalformedLockHolderAsStaleAndRemovesItOnTheExactStoredValue(array $lock, string $condition, ?array $expectedValue): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(new Result(['Item' => $lock]));
        $this->mock->append(new Result([]));
        $this->mock->append(new Result([]));

        $this->repository()->create(self::owner(), self::food());

        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'DeleteItem', 'TransactWriteItems');
        self::assertSame($condition, $this->commands[2]['ConditionExpression']);
        $values = $this->commands[2]['ExpressionAttributeValues'] ?? null;
        $placeholder = str_contains($condition, ':type') ? ':type' : ':stale';
        self::assertSame($expectedValue, is_array($values) ? ($values[$placeholder] ?? null) : null);
        self::assertSame('CATNAME#' . self::food()->nameKey, self::dig($this->commands[2], 'Key', 'SK', 'S'));
    }

    public function testCreateGivesUpAfterOneRepairOfAMalformedLock(): void
    {
        $malformed = new Result(['Item' => ['categoryId' => ['S' => 'food']]]);
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append($malformed);
        $this->mock->append(new Result([]));
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append($malformed);

        $this->expectException(TransactionCancelled::class);

        try {
            $this->repository()->create(self::owner(), self::food());
        } finally {
            $this->assertCommandNames('TransactWriteItems', 'GetItem', 'DeleteItem', 'TransactWriteItems', 'GetItem');
        }
    }

    // rename

    public function testRenameRefusesAnExpectedVersionThatIsNotTheCurrentOneAndSendsNothing(): void
    {
        $this->expectException(StaleVersion::class);

        try {
            $this->repository()->rename(self::owner(), self::food(), self::nameOf('Groceries'), 2, new DateTimeImmutable(self::LATER));
        } finally {
            self::assertSame([], $this->commands);
        }
    }

    public function testRenameSendsTheCategoryTheNewLockAndTheOldLockInThatOrder(): void
    {
        $this->mock->append(new Result([]));
        $food = self::food();

        $renamed = $this->repository()->rename(self::owner(), $food, self::nameOf('Groceries'), 1, new DateTimeImmutable(self::LATER));

        /** @var list<array<string, array<string, mixed>>> $items */
        $items = $this->commands[0]['TransactItems'];
        self::assertSame(['Update', 'Put', 'Delete'], array_map(static fn(array $item): string => (string) array_key_first($item), $items));
        self::assertSame('attribute_exists(PK) AND #version = :expected', $items[0]['Update']['ConditionExpression']);
        self::assertSame('CATNAME#' . $renamed->nameKey, self::dig($items[1], 'Put', 'Item', 'SK', 'S'));
        self::assertSame('attribute_not_exists(PK)', $items[1]['Put']['ConditionExpression']);
        self::assertSame('CATNAME#' . $food->nameKey, self::dig($items[2], 'Delete', 'Key', 'SK', 'S'));
        self::assertSame('#categoryId = :id', $items[2]['Delete']['ConditionExpression']);
        self::assertSame('2026-10-23T12:00:00+00:00', $renamed->updatedAt->format('c'));
    }

    /**
     * @param list<string> $reasons
     */
    #[DataProvider('overloadOrNoConditionFailure')]
    public function testRenameRethrowsACancellationThatIsNotAFailedConditionUnchanged(array $reasons): void
    {
        $this->mock->append(self::cancelled($reasons));

        try {
            $this->repository()->rename(self::owner(), self::food(), self::nameOf('Groceries'), 1, new DateTimeImmutable(self::LATER));
            self::fail('TransactionCancelled was expected.');
        } catch (TransactionCancelled $cancelled) {
            self::assertSame($reasons, $cancelled->reasons);
        }
        $this->assertCommandNames('TransactWriteItems');
    }

    public function testRenameWhoseOldLockConditionFailedRereadsAndResendsWithoutTheOldLockDelete(): void
    {
        $this->mock->append(self::cancelled(['None', 'None', 'ConditionalCheckFailed']));
        $this->mock->append(self::storedCategory());
        $this->mock->append(new Result([]));
        $groceries = self::nameOf('Groceries');

        $renamed = $this->repository()->rename(self::owner(), self::food(), $groceries, 1, new DateTimeImmutable(self::LATER));

        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'TransactWriteItems');
        self::assertSame(2, $renamed->version);
        /** @var list<array<string, array<string, mixed>>> $items */
        $items = $this->commands[2]['TransactItems'];
        self::assertSame(['Update', 'Put'], array_map(static fn(array $item): string => (string) array_key_first($item), $items));
        self::assertSame('attribute_exists(PK) AND #version = :expected', $items[0]['Update']['ConditionExpression']);
        self::assertSame('CATNAME#' . $groceries->key(), self::dig($items[1], 'Put', 'Item', 'SK', 'S'));
        self::assertSame('attribute_not_exists(PK)', $items[1]['Put']['ConditionExpression']);
    }

    public function testRenameWhoseOldLockConditionFailedAndWhoseCategoryChangedGoesThroughTheCategoryRules(): void
    {
        $this->mock->append(self::cancelled(['None', 'None', 'ConditionalCheckFailed']));
        $this->mock->append(self::storedCategory(['version' => 4]));

        $this->expectException(StaleVersion::class);

        try {
            $this->repository()->rename(self::owner(), self::food(), self::nameOf('Groceries'), 1, new DateTimeImmutable(self::LATER));
        } finally {
            $this->assertCommandNames('TransactWriteItems', 'GetItem');
        }
    }

    public function testRenameWhoseOldLockConditionFailedAndWhoseCategoryIsGoneIsNotFound(): void
    {
        $this->mock->append(self::cancelled(['None', 'None', 'ConditionalCheckFailed']));
        $this->mock->append(new Result([]));

        $this->expectException(CategoryNotFound::class);

        $this->repository()->rename(self::owner(), self::food(), self::nameOf('Groceries'), 1, new DateTimeImmutable(self::LATER));
    }

    public function testRenameWhoseOldLockConditionFailedAndWhoseCategoryHoldsOurRenameIsASuccessWithoutAResend(): void
    {
        $this->mock->append(self::cancelled(['None', 'None', 'ConditionalCheckFailed']));
        $groceries = self::nameOf('Groceries');
        $this->mock->append(self::storedCategory(['name' => 'Groceries', 'nameKey' => $groceries->key(), 'version' => 2, 'updatedAt' => '2026-10-23T12:00:00Z']));

        $renamed = $this->repository()->rename(self::owner(), self::food(), $groceries, 1, new DateTimeImmutable(self::LATER));

        $this->assertCommandNames('TransactWriteItems', 'GetItem');
        self::assertSame(2, $renamed->version);
    }

    public function testRenameWithoutTheOldLockDeleteStillMapsItsOwnCancellations(): void
    {
        $this->mock->append(self::cancelled(['None', 'None', 'ConditionalCheckFailed']));
        $this->mock->append(self::storedCategory());
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'None']));
        $this->mock->append(self::storedCategory(['version' => 2]));

        $this->expectException(StaleVersion::class);

        $this->repository()->rename(self::owner(), self::food(), self::nameOf('Groceries'), 1, new DateTimeImmutable(self::LATER));
    }

    public function testRenameWhoseCategoryConditionFailedButStoresExactlyWhatItWroteIsASuccess(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'None', 'ConditionalCheckFailed']));
        $groceries = self::nameOf('Groceries');
        $this->mock->append(self::storedCategory(['name' => 'Groceries', 'nameKey' => $groceries->key(), 'version' => 2, 'updatedAt' => '2026-10-23T12:00:00Z']));

        $renamed = $this->repository()->rename(self::owner(), self::food(), $groceries, 1, new DateTimeImmutable(self::LATER));

        $this->assertCommandNames('TransactWriteItems', 'GetItem');
        self::assertSame(2, $renamed->version);
        self::assertSame('Groceries', $renamed->name->value());
    }

    /**
     * @return iterable<string, array{array<string, string|int|null>}>
     */
    public static function storedCategoriesThatAreNotOurRename(): iterable
    {
        $groceries = self::nameOf('Groceries');
        $ours = ['name' => 'Groceries', 'nameKey' => $groceries->key(), 'version' => 2, 'updatedAt' => '2026-10-23T12:00:00Z'];
        yield 'another version' => [['version' => 3] + $ours];
        yield 'the old version' => [['version' => 1] + $ours];
        yield 'another name' => [['name' => 'Eating out'] + $ours];
        yield 'another key' => [['nameKey' => str_repeat('c', 64)] + $ours];
        yield 'another time' => [['updatedAt' => '2026-10-23T12:00:01Z'] + $ours];
    }

    /**
     * @param array<string, string|int|null> $stored
     */
    #[DataProvider('storedCategoriesThatAreNotOurRename')]
    public function testRenameWhoseCategoryConditionFailedAndStoresSomethingElseIsStale(array $stored): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'None', 'None']));
        $this->mock->append(self::storedCategory($stored));

        $this->expectException(StaleVersion::class);

        $this->repository()->rename(self::owner(), self::food(), self::nameOf('Groceries'), 1, new DateTimeImmutable(self::LATER));
    }

    public function testRenameWhoseCategoryConditionFailedAndIsGoneIsNotFound(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'None', 'ConditionalCheckFailed']));
        $this->mock->append(new Result([]));

        $this->expectException(CategoryNotFound::class);

        $this->repository()->rename(self::owner(), self::food(), self::nameOf('Groceries'), 1, new DateTimeImmutable(self::LATER));
    }

    public function testRenameBothFailingTakesTheCategoryAsTheCauseBeforeTheLock(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'ConditionalCheckFailed', 'None']));
        $this->mock->append(self::storedCategory(['version' => 5]));

        $this->expectException(StaleVersion::class);

        $this->repository()->rename(self::owner(), self::food(), self::nameOf('Groceries'), 1, new DateTimeImmutable(self::LATER));
    }

    public function testRenameToATakenNameIsCategoryNameTakenWhenTheHolderExists(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed', 'None']));
        $this->mock->append(self::lockHeldBy(self::OTHER_ID));
        $this->mock->append(self::storedCategory(['categoryId' => self::OTHER_ID, 'SK' => 'CAT#' . self::OTHER_ID]));

        $this->expectException(CategoryNameTaken::class);

        $this->repository()->rename(self::owner(), self::food(), self::nameOf('Groceries'), 1, new DateTimeImmutable(self::LATER));
    }

    public function testRenameWhoseNewLockAlreadyHoldsItsOwnIdChecksTheStoredCategory(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed', 'None']));
        $this->mock->append(self::lockHeldBy(self::FOOD_ID));
        $groceries = self::nameOf('Groceries');
        $this->mock->append(self::storedCategory(['name' => 'Groceries', 'nameKey' => $groceries->key(), 'version' => 2, 'updatedAt' => '2026-10-23T12:00:00Z']));

        $renamed = $this->repository()->rename(self::owner(), self::food(), $groceries, 1, new DateTimeImmutable(self::LATER));

        self::assertSame(2, $renamed->version);
        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'GetItem');
    }

    public function testACaseOnlyRenameIsOneUpdateItemWithTheVersionCondition(): void
    {
        $this->mock->append(new Result(['Attributes' => self::storedCategory(['name' => 'FOOD', 'version' => 2, 'updatedAt' => '2026-10-23T12:00:00Z'])['Item']]));

        $renamed = $this->repository()->rename(self::owner(), self::food(), self::nameOf('FOOD'), 1, new DateTimeImmutable(self::LATER));

        $this->assertCommandNames('UpdateItem');
        self::assertSame('attribute_exists(PK) AND #version = :expected', $this->commands[0]['ConditionExpression']);
        self::assertStringNotContainsString('nameKey', (string) self::dig($this->commands[0], 'UpdateExpression'));
        self::assertSame('FOOD', $renamed->name->value());
    }

    public function testACaseOnlyRenameWhoseConditionFailedAndStoresExactlyWhatItWroteIsASuccess(): void
    {
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::storedCategory(['name' => 'FOOD', 'version' => 2, 'updatedAt' => '2026-10-23T12:00:00Z']));

        $renamed = $this->repository()->rename(self::owner(), self::food(), self::nameOf('FOOD'), 1, new DateTimeImmutable(self::LATER));

        $this->assertCommandNames('UpdateItem', 'GetItem');
        self::assertSame(2, $renamed->version);
    }

    public function testACaseOnlyRenameWhoseConditionFailedBecauseSomeoneElseChangedItIsStale(): void
    {
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::storedCategory(['name' => 'Food!', 'version' => 2, 'updatedAt' => '2026-10-23T12:00:00Z']));

        $this->expectException(StaleVersion::class);

        $this->repository()->rename(self::owner(), self::food(), self::nameOf('FOOD'), 1, new DateTimeImmutable(self::LATER));
    }

    public function testACorruptItemAnUpdateReturnsNamesUpdateItem(): void
    {
        $this->mock->append(new Result(['Attributes' => self::storedCategory(['version' => 'two'])['Item']]));

        try {
            $this->repository()->rename(self::owner(), self::food(), self::nameOf('FOOD'), 1, new DateTimeImmutable(self::LATER));
            self::fail('StorageFailure was expected.');
        } catch (StorageFailure $failure) {
            self::assertSame('UpdateItem', $failure->operation);
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
        }
    }

    // delete

    /**
     * @param list<string> $reasons
     */
    #[DataProvider('overloadOrNoConditionFailure')]
    public function testDeleteRethrowsACancellationThatIsNotAFailedConditionUnchangedAndReadsNothing(array $reasons): void
    {
        $this->mock->append(self::cancelled($reasons));

        try {
            $this->repository()->delete(self::owner(), self::food());
            self::fail('TransactionCancelled was expected.');
        } catch (TransactionCancelled $cancelled) {
            self::assertSame($reasons, $cancelled->reasons);
        }
        $this->assertCommandNames('TransactWriteItems');
    }

    public function testDeleteSendsTheCategoryUnderItsVersionAndTheLockUnderItsStoredKey(): void
    {
        $this->mock->append(new Result([]));
        $stored = new Category(self::FOOD_ID, self::nameOf('Food'), str_repeat('b', 64), 3, new DateTimeImmutable(self::NOW), new DateTimeImmutable(self::NOW));

        $this->repository()->delete(self::owner(), $stored);

        /** @var list<array{Delete: array<string, mixed>}> $items */
        $items = $this->commands[0]['TransactItems'];
        self::assertSame('CAT#' . self::FOOD_ID, self::dig($items[0], 'Delete', 'Key', 'SK', 'S'));
        self::assertSame('attribute_exists(PK) AND #version = :version', $items[0]['Delete']['ConditionExpression']);
        self::assertSame('3', self::dig($items[0], 'Delete', 'ExpressionAttributeValues', ':version', 'N'));
        self::assertSame('CATNAME#' . str_repeat('b', 64), self::dig($items[1], 'Delete', 'Key', 'SK', 'S'));
        self::assertSame('#categoryId = :id', $items[1]['Delete']['ConditionExpression']);
    }

    public function testDeleteRereadsAfterAVersionRaceAndRetriesOnceWithTheStoredCategory(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'ConditionalCheckFailed']));
        $groceries = self::nameOf('Groceries');
        $this->mock->append(self::storedCategory(['name' => 'Groceries', 'nameKey' => $groceries->key(), 'version' => 2]));
        $this->mock->append(new Result([]));

        $this->repository()->delete(self::owner(), self::food());

        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'TransactWriteItems');
        /** @var list<array{Delete: array<string, mixed>}> $items */
        $items = $this->commands[2]['TransactItems'];
        self::assertSame('2', self::dig($items[0], 'Delete', 'ExpressionAttributeValues', ':version', 'N'));
        self::assertSame('CATNAME#' . $groceries->key(), self::dig($items[1], 'Delete', 'Key', 'SK', 'S'));
    }

    public function testDeleteGivesUpWithStaleVersionAfterTwoRaces(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'None']));
        $this->mock->append(self::storedCategory(['version' => 2]));
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'None']));
        $this->mock->append(self::storedCategory(['version' => 3]));

        $this->expectException(StaleVersion::class);

        try {
            $this->repository()->delete(self::owner(), self::food());
        } finally {
            $this->assertCommandNames('TransactWriteItems', 'GetItem', 'TransactWriteItems', 'GetItem');
        }
    }

    public function testDeleteOfACategoryThatVanishedIsNotFound(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'ConditionalCheckFailed']));
        $this->mock->append(new Result([]));

        $this->expectException(CategoryNotFound::class);

        $this->repository()->delete(self::owner(), self::food());
    }

    public function testDeleteRemovesTheCategoryAloneWhenOnlyTheLockConditionFailed(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(self::storedCategory());
        $this->mock->append(new Result([]));

        $this->repository()->delete(self::owner(), self::food());

        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'DeleteItem');
        self::assertSame('CAT#' . self::FOOD_ID, self::dig($this->commands[2], 'Key', 'SK', 'S'));
        self::assertSame('attribute_exists(PK) AND #version = :version', $this->commands[2]['ConditionExpression']);
    }

    public function testDeleteOfTheCategoryAloneThatLosesARaceTriesTheTransactionAgainWithTheStoredCategory(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(self::storedCategory());
        $this->mock->append(self::conditionFailed());
        $this->mock->append(new Result([]));

        $this->repository()->delete(self::owner(), self::food());

        $this->assertCommandNames('TransactWriteItems', 'GetItem', 'DeleteItem', 'TransactWriteItems');
    }

    // list and find

    public function testListAllFollowsThePagesOfTheQuery(): void
    {
        $this->mock->append(new Result([
            'Items' => [self::storedCategory()['Item']],
            'LastEvaluatedKey' => ['PK' => ['S' => 'USER#' . self::USER], 'SK' => ['S' => 'CAT#' . self::FOOD_ID]],
        ]));
        $this->mock->append(new Result([
            'Items' => [self::storedCategory(['categoryId' => self::OTHER_ID, 'SK' => 'CAT#' . self::OTHER_ID, 'name' => 'Travel'])['Item']],
        ]));

        $listed = $this->repository()->listAll(self::owner());

        self::assertSame([self::FOOD_ID, self::OTHER_ID], array_map(static fn(Category $category): string => $category->id, $listed));
        $this->assertCommandNames('Query', 'Query');
        self::assertSame('CAT#' . self::FOOD_ID, self::dig($this->commands[1], 'ExclusiveStartKey', 'SK', 'S'));
        self::assertSame('CAT#', self::dig($this->commands[0], 'ExpressionAttributeValues', ':prefix', 'S'));
    }

    public function testFindOfAnItemInAnotherPartitionIsCorrupt(): void
    {
        $this->mock->append(self::storedCategory(['PK' => 'USER#' . self::OTHER_ID]));

        $this->expectException(StorageFailure::class);

        $this->repository()->find(self::owner(), self::FOOD_ID);
    }

    public function testFindRefusesAnIdThatIsNotAUuidAndSendsNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->repository()->find(self::owner(), 'CAT#x');
        } finally {
            self::assertSame([], $this->commands);
        }
    }
}
