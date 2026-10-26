<?php

declare(strict_types=1);

namespace App\Tests\Integration\Categories;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryKeys;
use App\Categories\CategoryName;
use App\Categories\CategoryNameTaken;
use App\Categories\CategoryNotFound;
use App\Categories\CategoryRepository;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StaleVersion;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CategoryRepository against DynamoDB Local, on a table of its own. Ids, names and times are invented.
 */
final class CategoryRepositoryTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_USER = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const TRAVEL_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const NOW = '2026-10-23T10:00:00+00:00';

    private TemporaryTable $table;
    private ItemStore $store;
    private CategoryRepository $categories;

    protected function setUp(): void
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $this->table = TemporaryTable::create($client);
        $this->store = new ItemStore($client, $this->table->name);
        $this->categories = new CategoryRepository($this->store);
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    private static function owner(string $userId = self::USER): Principal
    {
        return new Principal($userId, str_repeat('a', 64));
    }

    private static function category(string $id, string $text, string $at = self::NOW): Category
    {
        $name = CategoryName::parse($text);
        self::assertInstanceOf(CategoryName::class, $name);

        return Category::new($id, $name, new DateTimeImmutable($at));
    }

    public function testCreateThenFindAndListAll(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $travel = self::category(self::TRAVEL_ID, 'Travel', '2026-10-23T11:00:00+00:00');

        $this->categories->create(self::owner(), $food);
        $this->categories->create(self::owner(), $travel);

        $found = $this->categories->find(self::owner(), self::FOOD_ID);
        self::assertNotNull($found);
        self::assertSame(self::FOOD_ID, $found->id);
        self::assertSame('Food', $found->name->value());
        self::assertSame($food->nameKey, $found->nameKey);
        self::assertSame(1, $found->version);
        self::assertSame('2026-10-23T10:00:00+00:00', $found->createdAt->format('c'));
        self::assertSame('2026-10-23T10:00:00+00:00', $found->updatedAt->format('c'));
        $listed = $this->categories->listAll(self::owner());
        $ids = array_map(static fn(Category $category): string => $category->id, $listed);
        sort($ids);
        self::assertSame([self::TRAVEL_ID, self::FOOD_ID], $ids);
    }

    public function testWhatCreateAndRenameReturnOrWriteEqualsWhatIsReadBackEvenWithMicroseconds(): void
    {
        $created = self::category(self::FOOD_ID, 'Food', '2026-10-23T10:00:00.123456+00:00');
        $this->categories->create(self::owner(), $created);

        self::assertEquals($created, $this->categories->find(self::owner(), self::FOOD_ID));

        foreach (['FOOD', 'Groceries'] as $target) {
            $current = $this->categories->find(self::owner(), self::FOOD_ID);
            self::assertNotNull($current);
            $returned = $this->categories->rename(self::owner(), $current, self::nameOf($target), $current->version, new DateTimeImmutable('2026-10-23T12:00:00.654321+00:00'));

            self::assertEquals($returned, $this->categories->find(self::owner(), self::FOOD_ID), "rename to $target returns what is stored");
        }
    }

    public function testFindIsNullForAnUnknownCategoryAndForAnotherUsersCategory(): void
    {
        $this->categories->create(self::owner(), self::category(self::FOOD_ID, 'Food'));

        self::assertNull($this->categories->find(self::owner(), self::TRAVEL_ID));
        self::assertNull($this->categories->find(self::owner(self::OTHER_USER), self::FOOD_ID));
        self::assertSame([], $this->categories->listAll(self::owner(self::OTHER_USER)));
    }

    public function testTheStoredItemsHaveExactlyTheDocumentedAttributes(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');

        $this->categories->create(self::owner(), $food);

        $item = $this->store->getItem(CategoryKeys::category(self::owner(), self::FOOD_ID));
        self::assertSame([
            'PK' => 'USER#' . self::USER,
            'SK' => 'CAT#' . self::FOOD_ID,
            'categoryId' => self::FOOD_ID,
            'createdAt' => '2026-10-23T10:00:00Z',
            'name' => 'Food',
            'nameKey' => $food->nameKey,
            'updatedAt' => '2026-10-23T10:00:00Z',
            'version' => 1,
        ], self::sorted($item));
        $lock = $this->store->getItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey));
        self::assertSame(['PK' => 'USER#' . self::USER, 'SK' => 'CATNAME#' . $food->nameKey, 'categoryId' => self::FOOD_ID], self::sorted($lock));
    }

    public function testAProfileAnExpenseAndNameLocksNeverAppearInListAll(): void
    {
        $this->categories->create(self::owner(), self::category(self::FOOD_ID, 'Food'));
        $userKey = 'USER#' . self::USER;
        $this->store->putItem(['PK' => $userKey, 'SK' => 'PROFILE', 'userId' => self::USER, 'passwordHash' => 'invented-hash']);
        $this->store->putItem(['PK' => $userKey, 'SK' => 'EXP#' . self::TRAVEL_ID, 'expenseId' => self::TRAVEL_ID, 'description' => 'Train']);

        $listed = $this->categories->listAll(self::owner());

        self::assertSame([self::FOOD_ID], array_map(static fn(Category $category): string => $category->id, $listed));
    }

    /**
     * @param array<string, mixed> $override a null value removes the attribute
     */
    #[DataProvider('corruptFields')]
    public function testACorruptItemIsAStorageFailureForFindAndForListAll(array $override): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $valid = [
            'PK' => 'USER#' . self::USER,
            'SK' => 'CAT#' . self::FOOD_ID,
            'categoryId' => self::FOOD_ID,
            'name' => 'Food',
            'nameKey' => $food->nameKey,
            'version' => 1,
            'createdAt' => '2026-10-23T10:00:00Z',
            'updatedAt' => '2026-10-23T10:00:00Z',
        ];
        $this->store->putItem(array_filter($override + $valid, static fn(mixed $value): bool => $value !== null));

        foreach ([
            'GetItem' => fn() => $this->categories->find(self::owner(), self::FOOD_ID),
            'Query' => fn() => $this->categories->listAll(self::owner()),
        ] as $operation => $read) {
            try {
                $read();
                self::fail('A StorageFailure was expected.');
            } catch (StorageFailure $failure) {
                self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
                self::assertSame($operation, $failure->operation, 'the failure names the operation that read the item');
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function corruptFields(): iterable
    {
        yield 'no categoryId' => [['categoryId' => null]];
        yield 'categoryId of another category' => [['categoryId' => self::TRAVEL_ID]];
        yield 'categoryId that is not a uuid' => [['categoryId' => 'food']];
        yield 'categoryId of the wrong type' => [['categoryId' => 7]];
        yield 'no name' => [['name' => null]];
        yield 'name of the wrong type' => [['name' => 42]];
        yield 'empty name' => [['name' => '']];
        yield 'name with a control character' => [['name' => "Fo\x01od"]];
        yield 'name that is not trimmed' => [['name' => ' Food ']];
        yield 'no nameKey' => [['nameKey' => null]];
        yield 'nameKey of the wrong type' => [['nameKey' => 12]];
        yield 'nameKey that is not sha256 hex' => [['nameKey' => 'Food']];
        yield 'no version' => [['version' => null]];
        yield 'version as a string' => [['version' => '1']];
        yield 'version as a float' => [['version' => 1.5]];
        yield 'version zero' => [['version' => 0]];
        yield 'no createdAt' => [['createdAt' => null]];
        yield 'createdAt of the wrong type' => [['createdAt' => 20261023]];
        yield 'createdAt not in the stored format' => [['createdAt' => '2026-10-23 10:00:00']];
        yield 'createdAt that is not a real date' => [['createdAt' => '2026-02-31T10:00:00Z']];
        yield 'no updatedAt' => [['updatedAt' => null]];
        yield 'updatedAt with an offset' => [['updatedAt' => '2026-10-23T10:00:00+00:00']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemsWithSortKeyPrefix(string $prefix, string $userId = self::USER): array
    {
        return $this->store->query(
            '#pk = :pk AND begins_with(SK, :prefix)',
            ['#pk' => 'PK'],
            [':pk' => 'USER#' . $userId, ':prefix' => $prefix],
        )->items;
    }

    public function testTheSameNameInAnotherCaseIsCategoryNameTakenAndNothingIsAdded(): void
    {
        $this->categories->create(self::owner(), self::category(self::FOOD_ID, 'Food'));

        try {
            $this->categories->create(self::owner(), self::category(self::TRAVEL_ID, 'food'));
            self::fail('CategoryNameTaken was expected.');
        } catch (CategoryNameTaken) {
            // expected
        }

        self::assertCount(1, $this->itemsWithSortKeyPrefix(CategoryKeys::PREFIX));
        self::assertCount(1, $this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX));
        self::assertNull($this->categories->find(self::owner(), self::TRAVEL_ID));
    }

    public function testCreatingTheSameCategoryTwiceSucceedsAndStoresOneOfEach(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');

        $this->categories->create(self::owner(), $food);
        $this->categories->create(self::owner(), $food);

        self::assertCount(1, $this->itemsWithSortKeyPrefix(CategoryKeys::PREFIX));
        self::assertCount(1, $this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX));
    }

    public function testALockWhoseCategoryDoesNotExistIsRepairedAndTheCreateSucceeds(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->store->putItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey) + ['categoryId' => self::TRAVEL_ID]);

        $this->categories->create(self::owner(), $food);

        $lock = $this->store->getItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey));
        self::assertSame(self::FOOD_ID, $lock['categoryId'] ?? null);
        self::assertNotNull($this->categories->find(self::owner(), self::FOOD_ID));
        self::assertCount(1, $this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedLockHolders(): iterable
    {
        yield 'a string that is not a uuid' => [['categoryId' => 'not-a-uuid']];
        yield 'a number' => [['categoryId' => 7]];
        yield 'an upper-case uuid' => [['categoryId' => strtoupper(self::TRAVEL_ID)]];
        yield 'no categoryId at all' => [['other' => 'x']];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('malformedLockHolders')]
    public function testALockWithAMalformedHolderIsStaleAndTheCreateSucceeds(array $attributes): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $lockKey = CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey);
        $this->store->putItem($lockKey + $attributes);

        $this->categories->create(self::owner(), $food);

        $lock = $this->store->getItem($lockKey);
        self::assertSame(self::FOOD_ID, $lock['categoryId'] ?? null);
        self::assertNotNull($this->categories->find(self::owner(), self::FOOD_ID));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('malformedLockHolders')]
    public function testALockWithAMalformedHolderIsStaleAndTheRenameSucceeds(array $attributes): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $groceries = self::nameOf('Groceries');
        $lockKey = CategoryKeys::nameLockOfStoredKey(self::owner(), $groceries->key());
        $this->store->putItem($lockKey + $attributes);

        $renamed = $this->categories->rename(self::owner(), $food, $groceries, 1, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));

        self::assertSame(2, $renamed->version);
        $lock = $this->store->getItem($lockKey);
        self::assertSame(self::FOOD_ID, $lock['categoryId'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonStringLockHolders(): iterable
    {
        yield 'a number that loses precision as a float' => [['N' => '12345678901234567890123']];
        yield 'a map' => [['M' => ['a' => ['S' => 'x']]]];
        yield 'a list' => [['L' => [['S' => 'x']]]];
        yield 'a boolean' => [['BOOL' => true]];
        yield 'a null' => [['NULL' => true]];
    }

    /**
     * Seeds a lock with a `categoryId` of an exact DynamoDB type, bypassing the store's PHP-value marshalling.
     *
     * @param array<string, mixed> $holder
     *
     * @return array{PK: string, SK: string}
     */
    private function seedRawLock(string $nameKey, array $holder): array
    {
        $key = CategoryKeys::nameLockOfStoredKey(self::owner(), $nameKey);
        // The SDK's own array shape cannot express a holder of any attribute type.
        // @phpstan-ignore argument.type
        LocalAws::fromGlobals()->dynamoDb()->putItem([
            'TableName' => $this->table->name,
            'Item' => ['PK' => ['S' => $key['PK']], 'SK' => ['S' => $key['SK']], 'categoryId' => $holder],
        ]);

        return $key;
    }

    /**
     * @param array<string, mixed> $holder
     */
    #[DataProvider('nonStringLockHolders')]
    public function testALockWhoseHolderIsNotAStringIsStaleAndTheCreateSucceeds(array $holder): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $lockKey = $this->seedRawLock($food->nameKey, $holder);

        $this->categories->create(self::owner(), $food);

        $lock = $this->store->getItem($lockKey);
        self::assertSame(self::FOOD_ID, $lock['categoryId'] ?? null);
    }

    /**
     * @param array<string, mixed> $holder
     */
    #[DataProvider('nonStringLockHolders')]
    public function testALockWhoseHolderIsNotAStringIsStaleAndTheRenameSucceeds(array $holder): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $groceries = self::nameOf('Groceries');
        $lockKey = $this->seedRawLock($groceries->key(), $holder);

        $renamed = $this->categories->rename(self::owner(), $food, $groceries, 1, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));

        self::assertSame(2, $renamed->version);
        $lock = $this->store->getItem($lockKey);
        self::assertSame(self::FOOD_ID, $lock['categoryId'] ?? null);
    }

    public function testALockHeldByARealCategoryIsNotRepaired(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);

        try {
            $this->categories->create(self::owner(), self::category(self::TRAVEL_ID, 'FOOD'));
            self::fail('CategoryNameTaken was expected.');
        } catch (CategoryNameTaken) {
            // expected
        }

        $lock = $this->store->getItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey));
        self::assertSame(self::FOOD_ID, $lock['categoryId'] ?? null);
    }

    private static function nameOf(string $text): CategoryName
    {
        $name = CategoryName::parse($text);
        self::assertInstanceOf(CategoryName::class, $name);

        return $name;
    }

    public function testACaseOnlyRenameKeepsTheOneLockAndBumpsTheVersion(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $later = new DateTimeImmutable('2026-10-23T12:00:00+00:00');

        $renamed = $this->categories->rename(self::owner(), $food, self::nameOf('FOOD'), 1, $later);

        self::assertSame('FOOD', $renamed->name->value());
        self::assertSame(2, $renamed->version);
        self::assertSame($food->nameKey, $renamed->nameKey);
        $stored = $this->categories->find(self::owner(), self::FOOD_ID);
        self::assertNotNull($stored);
        self::assertSame('FOOD', $stored->name->value());
        self::assertSame(2, $stored->version);
        self::assertSame('2026-10-23T10:00:00+00:00', $stored->createdAt->format('c'));
        self::assertSame('2026-10-23T12:00:00+00:00', $stored->updatedAt->format('c'));
        $locks = $this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX);
        self::assertCount(1, $locks);
        self::assertSame(self::FOOD_ID, $locks[0]['categoryId']);
    }

    public function testARenameToAnotherNameMovesTheLockAndFreesTheOldName(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $later = new DateTimeImmutable('2026-10-23T12:00:00+00:00');

        $renamed = $this->categories->rename(self::owner(), $food, self::nameOf('Groceries'), 1, $later);

        self::assertSame('Groceries', $renamed->name->value());
        self::assertSame(self::nameOf('Groceries')->key(), $renamed->nameKey);
        self::assertSame(2, $renamed->version);
        $stored = $this->categories->find(self::owner(), self::FOOD_ID);
        self::assertNotNull($stored);
        self::assertSame($renamed->nameKey, $stored->nameKey);
        self::assertNull($this->store->getItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey)));
        $locks = $this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX);
        self::assertCount(1, $locks);
        self::assertSame('CATNAME#' . $renamed->nameKey, $locks[0]['SK']);
        self::assertSame(self::FOOD_ID, $locks[0]['categoryId']);
        // the old name can be used again
        $this->categories->create(self::owner(), self::category(self::TRAVEL_ID, 'food'));
        self::assertCount(2, $this->categories->listAll(self::owner()));
    }

    public function testARenameToATakenNameChangesNothing(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $travel = self::category(self::TRAVEL_ID, 'Travel');
        $this->categories->create(self::owner(), $food);
        $this->categories->create(self::owner(), $travel);
        $before = $this->snapshot();

        try {
            $this->categories->rename(self::owner(), $travel, self::nameOf('FOOD'), 1, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));
            self::fail('CategoryNameTaken was expected.');
        } catch (CategoryNameTaken) {
            // expected
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testDeleteRemovesTheCategoryAndItsLockAndLeavesTheOthers(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $travel = self::category(self::TRAVEL_ID, 'Travel');
        $this->categories->create(self::owner(), $food);
        $this->categories->create(self::owner(), $travel);

        $this->categories->delete(self::owner(), $food);

        self::assertNull($this->categories->find(self::owner(), self::FOOD_ID));
        self::assertNull($this->store->getItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey)));
        self::assertNotNull($this->categories->find(self::owner(), self::TRAVEL_ID));
        self::assertNotNull($this->store->getItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $travel->nameKey)));
    }

    public function testDeletingAMissingCategoryIsNotFound(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $this->categories->delete(self::owner(), $food);

        $this->expectException(CategoryNotFound::class);

        $this->categories->delete(self::owner(), $food);
    }

    public function testDeleteOfACategoryRenamedSinceItWasReadRemovesTheCurrentLockToo(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $renamed = $this->categories->rename(self::owner(), $food, self::nameOf('Groceries'), 1, new DateTimeImmutable('2026-10-23T11:00:00+00:00'));

        $this->categories->delete(self::owner(), $food);

        self::assertSame([], $this->snapshot());
        self::assertNotSame($food->nameKey, $renamed->nameKey);
    }

    public function testDeleteReleasesTheLockUnderTheStoredKeyNotARecomputedOne(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $storedKey = str_repeat('b', 64);
        $odd = new Category(self::FOOD_ID, self::nameOf('Food'), $storedKey, 1, $now, $now);
        $this->categories->create(self::owner(), $odd);
        self::assertNotNull($this->store->getItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $storedKey)));
        $found = $this->categories->find(self::owner(), self::FOOD_ID);
        self::assertNotNull($found);
        self::assertSame($storedKey, $found->nameKey);

        $this->categories->delete(self::owner(), $found);

        self::assertSame([], $this->snapshot());
    }

    public function testDeleteOfACategoryWhoseLockIsMissingStillRemovesTheCategory(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $this->store->deleteItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey));

        $this->categories->delete(self::owner(), $food);

        self::assertSame([], $this->snapshot());
    }

    public function testDeleteNeverRemovesALockThatBelongsToAnotherCategory(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $lockKey = CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey);
        $this->store->putItem($lockKey + ['categoryId' => self::TRAVEL_ID]);

        $this->categories->delete(self::owner(), $food);

        self::assertNull($this->categories->find(self::owner(), self::FOOD_ID));
        $lock = $this->store->getItem($lockKey);
        self::assertSame(self::TRAVEL_ID, $lock['categoryId'] ?? null);
    }

    public function testARenameToANameWhoseLockHasNoCategoryRepairsTheLockAndSucceeds(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $groceries = self::nameOf('Groceries');
        $this->store->putItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $groceries->key()) + ['categoryId' => self::TRAVEL_ID]);

        $renamed = $this->categories->rename(self::owner(), $food, $groceries, 1, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));

        self::assertSame(2, $renamed->version);
        $locks = $this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX);
        self::assertCount(1, $locks);
        self::assertSame('CATNAME#' . $groceries->key(), $locks[0]['SK']);
        self::assertSame(self::FOOD_ID, $locks[0]['categoryId']);
    }

    public function testARenameOfACategoryWhoseLockIsMissingStillSucceedsAndCreatesTheNewLock(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $this->store->deleteItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey));
        $groceries = self::nameOf('Groceries');

        $renamed = $this->categories->rename(self::owner(), $food, $groceries, 1, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));

        self::assertSame(2, $renamed->version);
        $locks = $this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX);
        self::assertCount(1, $locks);
        self::assertSame('CATNAME#' . $groceries->key(), $locks[0]['SK']);
        self::assertSame(self::FOOD_ID, $locks[0]['categoryId']);
    }

    public function testARenameOfACategoryWhoseOldLockBelongsToAnotherCategoryLeavesThatLockAlone(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $oldLockKey = CategoryKeys::nameLockOfStoredKey(self::owner(), $food->nameKey);
        $this->store->putItem($oldLockKey + ['categoryId' => self::TRAVEL_ID]);
        $groceries = self::nameOf('Groceries');

        $renamed = $this->categories->rename(self::owner(), $food, $groceries, 1, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));

        self::assertSame(2, $renamed->version);
        $stored = $this->categories->find(self::owner(), self::FOOD_ID);
        self::assertNotNull($stored);
        self::assertSame($groceries->key(), $stored->nameKey);
        $foreign = $this->store->getItem($oldLockKey);
        self::assertSame(self::TRAVEL_ID, $foreign['categoryId'] ?? null);
        $newLock = $this->store->getItem(CategoryKeys::nameLockOfStoredKey(self::owner(), $groceries->key()));
        self::assertSame(self::FOOD_ID, $newLock['categoryId'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function renameTargets(): iterable
    {
        yield 'a case-only rename' => ['FOOD'];
        yield 'a rename to another name' => ['Groceries'];
    }

    #[DataProvider('renameTargets')]
    public function testARenameWithAnOldVersionIsStaleAndChangesNothing(string $target): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $before = $this->snapshot();

        $this->expectException(StaleVersion::class);

        try {
            $this->categories->rename(self::owner(), $food, self::nameOf($target), 0, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));
        } finally {
            self::assertSame($before, $this->snapshot());
        }
    }

    #[DataProvider('renameTargets')]
    public function testARenameOfACategoryThatChangedSinceItWasReadIsStaleAndChangesNothing(string $target): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $this->categories->rename(self::owner(), $food, self::nameOf('Eating out'), 1, new DateTimeImmutable('2026-10-23T11:00:00+00:00'));
        $before = $this->snapshot();

        $this->expectException(StaleVersion::class);

        try {
            $this->categories->rename(self::owner(), $food, self::nameOf($target), 1, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));
        } finally {
            self::assertSame($before, $this->snapshot());
        }
    }

    #[DataProvider('renameTargets')]
    public function testARenameOfACategoryThatWasDeletedIsNotFoundAndLeavesNoLock(string $target): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        $this->categories->delete(self::owner(), $food);

        $this->expectException(CategoryNotFound::class);

        try {
            $this->categories->rename(self::owner(), $food, self::nameOf($target), 1, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));
        } finally {
            self::assertSame([], $this->snapshot());
        }
    }

    /**
     * Every category and name lock item of the user, in key order.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        $items = [...$this->itemsWithSortKeyPrefix(CategoryKeys::PREFIX), ...$this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX)];
        usort($items, static fn(array $a, array $b): int => $a['SK'] <=> $b['SK']);

        return $items;
    }

    public function testTwoUsersMayHoldTheSameName(): void
    {
        $this->categories->create(self::owner(), self::category(self::FOOD_ID, 'Food'));

        $this->categories->create(self::owner(self::OTHER_USER), self::category(self::TRAVEL_ID, 'Food'));

        self::assertCount(1, $this->categories->listAll(self::owner()));
        self::assertCount(1, $this->categories->listAll(self::owner(self::OTHER_USER)));
    }

    /**
     * @param array<string, mixed>|null $item
     *
     * @return array<string, mixed>
     */
    private static function sorted(?array $item): array
    {
        self::assertNotNull($item);
        ksort($item);

        return array_merge(['PK' => $item['PK'], 'SK' => $item['SK']], array_diff_key($item, ['PK' => 1, 'SK' => 1]));
    }
}
