<?php

declare(strict_types=1);

namespace App\Tests\Integration\Expenses;

use App\Auth\Session\Principal;
use App\Expenses\Amount;
use App\Expenses\Description;
use App\Expenses\Expense;
use App\Expenses\ExpenseBatch;
use App\Expenses\ExpenseDate;
use App\Expenses\ExpenseKeys;
use App\Expenses\ExpenseNotFound;
use App\Expenses\ExpenseRepository;
use App\Expenses\InvalidStartKey;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StaleVersion;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ExpenseRepository against DynamoDB Local, on a table of its own. Ids, descriptions and times are invented.
 */
final class ExpenseRepositoryTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_USER = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const LUNCH_ID = '5c8e1f3a-2b4d-4a6c-8e0f-1a2b3c4d5e6f';
    private const TRAIN_ID = '7e1a2b3c-4d5e-4f60-8a1b-2c3d4e5f6071';
    private const COFFEE_ID = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
    private const FOURTH_ID = 'd4e5f607-1829-4a3b-9c4d-5e6f70819203';
    private const FIFTH_ID = 'e5f60718-293a-4b4c-8d5e-6f7081920314';
    private const CATEGORY_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const NULL_TYPE = "\0null-type";
    private const NOW = '2026-10-23T10:00:00+00:00';
    private const LATER = '2026-10-24T11:00:00+00:00';

    private TemporaryTable $table;
    private ItemStore $store;
    private ExpenseRepository $expenses;

    protected function setUp(): void
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $this->table = TemporaryTable::create($client);
        $this->store = new ItemStore($client, $this->table->name);
        $this->expenses = new ExpenseRepository($this->store);
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    private static function owner(string $userId = self::USER): Principal
    {
        return new Principal($userId, str_repeat('a', 64));
    }

    private static function date(string $text): ExpenseDate
    {
        $date = ExpenseDate::parse($text);
        self::assertInstanceOf(ExpenseDate::class, $date);

        return $date;
    }

    private static function amount(int $cents): Amount
    {
        $amount = Amount::parse($cents);
        self::assertInstanceOf(Amount::class, $amount);

        return $amount;
    }

    private static function description(string $text): Description
    {
        $description = Description::parse($text);
        self::assertInstanceOf(Description::class, $description);

        return $description;
    }

    private static function expense(string $id, string $date = '2026-10-20', int $cents = 1250, string $text = 'Lunch', ?string $categoryId = null, string $at = self::NOW): Expense
    {
        return Expense::new($id, self::date($date), self::amount($cents), self::description($text), $categoryId, new DateTimeImmutable($at));
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

        return $item;
    }

    // create, find

    public function testCreateThenFindReturnsTheSameExpense(): void
    {
        $lunch = self::expense(self::LUNCH_ID, categoryId: self::CATEGORY_ID);

        $this->expenses->create(self::owner(), $lunch);

        $found = $this->expenses->find(self::owner(), self::LUNCH_ID);
        self::assertEquals($lunch, $found);
        self::assertSame(1250, $found?->amount->cents());
        self::assertSame(self::CATEGORY_ID, $found->categoryId);
    }

    public function testWhatCreateAndUpdateReturnEqualsWhatIsReadBackEvenWithMicroseconds(): void
    {
        $created = self::expense(self::LUNCH_ID, at: '2026-10-23T10:00:00.123456+00:00');
        $this->expenses->create(self::owner(), $created);
        self::assertEquals($created, $this->expenses->find(self::owner(), self::LUNCH_ID));

        $changed = $created->updated(self::date('2026-10-21'), self::amount(900), self::description('Coffee'), null, new DateTimeImmutable('2026-10-23T12:00:00.654321+00:00'));
        $returned = $this->expenses->update(self::owner(), $changed, 1);

        self::assertEquals($returned, $this->expenses->find(self::owner(), self::LUNCH_ID));
        self::assertEquals($changed, $returned);
    }

    public function testFindIsNullForAnUnknownExpenseAndForAnotherUsersExpense(): void
    {
        $this->expenses->create(self::owner(), self::expense(self::LUNCH_ID));

        self::assertNull($this->expenses->find(self::owner(), self::TRAIN_ID));
        self::assertNull($this->expenses->find(self::owner(self::OTHER_USER), self::LUNCH_ID));
    }

    public function testTheStoredItemHasExactlyTheDocumentedAttributesAndAmountIsANumber(): void
    {
        $this->expenses->create(self::owner(), self::expense(self::LUNCH_ID, categoryId: self::CATEGORY_ID));

        $item = $this->store->getItem(ExpenseKeys::expense(self::owner(), self::LUNCH_ID));

        self::assertSame([
            'LSI1SK' => 'EXP#2026-10-20#' . self::LUNCH_ID,
            'PK' => 'USER#' . self::USER,
            'SK' => 'EXP#' . self::LUNCH_ID,
            'amountCents' => 1250,
            'categoryId' => self::CATEGORY_ID,
            'createdAt' => '2026-10-23T10:00:00Z',
            'date' => '2026-10-20',
            'description' => 'Lunch',
            'expenseId' => self::LUNCH_ID,
            'updatedAt' => '2026-10-23T10:00:00Z',
            'version' => 1,
        ], self::sorted($item));
    }

    public function testAnExpenseWithoutACategoryStoresNoCategoryIdAttribute(): void
    {
        $this->expenses->create(self::owner(), self::expense(self::LUNCH_ID));

        $item = $this->store->getItem(ExpenseKeys::expense(self::owner(), self::LUNCH_ID));

        self::assertArrayNotHasKey('categoryId', self::sorted($item));
        self::assertNull($this->expenses->find(self::owner(), self::LUNCH_ID)?->categoryId);
    }

    public function testACategoryIdIsStoredAsGivenEvenWhenNoSuchCategoryExists(): void
    {
        $this->expenses->create(self::owner(), self::expense(self::LUNCH_ID, categoryId: self::CATEGORY_ID));

        self::assertSame(self::CATEGORY_ID, $this->expenses->find(self::owner(), self::LUNCH_ID)?->categoryId);
    }

    public function testReplayingTheSameCreateSucceedsBecauseTheStoredItemEqualsOurs(): void
    {
        $lunch = self::expense(self::LUNCH_ID, categoryId: self::CATEGORY_ID);
        $this->expenses->create(self::owner(), $lunch);

        $this->expenses->create(self::owner(), $lunch);

        self::assertEquals($lunch, $this->expenses->find(self::owner(), self::LUNCH_ID));
    }

    public function testCreateOverADifferentExpenseWithTheSameIdFailsAndKeepsTheStoredOne(): void
    {
        $first = self::expense(self::LUNCH_ID, text: 'Lunch');
        $this->expenses->create(self::owner(), $first);

        try {
            $this->expenses->create(self::owner(), self::expense(self::LUNCH_ID, text: 'Dinner'));
            self::fail('A different expense with a taken id must not be accepted.');
        } catch (ConditionFailed) {
            self::assertEquals($first, $this->expenses->find(self::owner(), self::LUNCH_ID));
        }
    }

    // update

    private function createLunch(?string $categoryId = self::CATEGORY_ID): Expense
    {
        $lunch = self::expense(self::LUNCH_ID, categoryId: $categoryId);
        $this->expenses->create(self::owner(), $lunch);

        return $lunch;
    }

    private static function change(Expense $from, string $date = '2026-10-21', int $cents = 900, string $text = 'Coffee', ?string $categoryId = null, string $at = self::LATER): Expense
    {
        return $from->updated(self::date($date), self::amount($cents), self::description($text), $categoryId, new DateTimeImmutable($at));
    }

    public function testUpdateWritesEveryEditableFieldAndTheNextVersion(): void
    {
        $changed = self::change($this->createLunch(), categoryId: self::CATEGORY_ID);

        $returned = $this->expenses->update(self::owner(), $changed, 1);

        $stored = $this->expenses->find(self::owner(), self::LUNCH_ID);
        self::assertNotNull($stored);
        self::assertEquals($changed, $returned);
        self::assertEquals($changed, $stored);
        self::assertSame(2, $stored->version);
        self::assertSame('2026-10-21', $stored->date->value());
        self::assertSame(900, $stored->amount->cents());
        self::assertSame('Coffee', $stored->description->value());
        self::assertSame('2026-10-23T10:00:00+00:00', $stored->createdAt->format('c'));
        self::assertSame('2026-10-24T11:00:00+00:00', $stored->updatedAt->format('c'));
    }

    public function testADateChangeMovesTheItemInTheIndexAndKeepsItsId(): void
    {
        $lunch = $this->createLunch();
        $this->expenses->update(self::owner(), self::change($lunch, date: '2026-11-05'), 1);

        $item = $this->store->getItem(ExpenseKeys::expense(self::owner(), self::LUNCH_ID));

        self::assertSame('EXP#2026-11-05#' . self::LUNCH_ID, $item['LSI1SK'] ?? null);
        self::assertSame([], $this->idsInRange('2026-10-01', '2026-10-31'), 'no longer in the old month');
        self::assertSame([self::LUNCH_ID], $this->idsInRange('2026-11-01', '2026-11-30'), 'in the new month, same id');
    }

    /**
     * @return list<string>
     */
    private function idsInRange(string $from, string $to): array
    {
        $page = $this->store->query(
            '#pk = :pk AND LSI1SK BETWEEN :from AND :to',
            ['#pk' => 'PK'],
            [':pk' => 'USER#' . self::USER, ':from' => ExpenseKeys::rangeStart(self::date($from)), ':to' => ExpenseKeys::rangeEnd(self::date($to))],
            'LSI1',
        );

        $ids = [];
        foreach ($page->items as $item) {
            self::assertIsString($item['expenseId'] ?? null);
            $ids[] = $item['expenseId'];
        }

        return $ids;
    }

    public function testSettingTheCategoryToNullRemovesTheAttribute(): void
    {
        $changed = self::change($this->createLunch(self::CATEGORY_ID), categoryId: null);

        $returned = $this->expenses->update(self::owner(), $changed, 1);

        self::assertNull($returned->categoryId);
        self::assertArrayNotHasKey('categoryId', self::sorted($this->store->getItem(ExpenseKeys::expense(self::owner(), self::LUNCH_ID))));
        self::assertNull($this->expenses->find(self::owner(), self::LUNCH_ID)?->categoryId);
    }

    public function testAnUpdateWithAnOldVersionGivesStaleVersionAndChangesNothing(): void
    {
        $lunch = $this->createLunch();
        $this->expenses->update(self::owner(), self::change($lunch, text: 'Coffee'), 1);

        try {
            $this->expenses->update(self::owner(), self::change($lunch, text: 'Tea', at: '2026-10-25T09:00:00+00:00'), 1);
            self::fail('A stale update must not be accepted.');
        } catch (StaleVersion) {
            self::assertSame('Coffee', $this->expenses->find(self::owner(), self::LUNCH_ID)?->description->value());
            self::assertSame(2, $this->expenses->find(self::owner(), self::LUNCH_ID)->version);
        }
    }

    public function testAnUpdateOfAMissingExpenseGivesExpenseNotFoundAndCreatesNothing(): void
    {
        $ghost = self::expense(self::TRAIN_ID);

        try {
            $this->expenses->update(self::owner(), self::change($ghost), 1);
            self::fail('Updating a missing expense must not create it.');
        } catch (ExpenseNotFound) {
            self::assertNull($this->expenses->find(self::owner(), self::TRAIN_ID));
        }
    }

    public function testAnUpdateByAnotherUserIsExpenseNotFoundAndTheOwnersExpenseStaysAsItWas(): void
    {
        $lunch = $this->createLunch();

        try {
            $this->expenses->update(self::owner(self::OTHER_USER), self::change($lunch), 1);
            self::fail('Another user must not update the expense.');
        } catch (ExpenseNotFound) {
            self::assertEquals($lunch, $this->expenses->find(self::owner(), self::LUNCH_ID));
            self::assertNull($this->expenses->find(self::owner(self::OTHER_USER), self::LUNCH_ID));
        }
    }

    public function testReplayingTheSameUpdateWithACategorySucceeds(): void
    {
        $changed = self::change($this->createLunch(null), categoryId: self::CATEGORY_ID);
        $first = $this->expenses->update(self::owner(), $changed, 1);

        $replayed = $this->expenses->update(self::owner(), $changed, 1);

        self::assertEquals($first, $replayed);
        self::assertSame(self::CATEGORY_ID, $this->expenses->find(self::owner(), self::LUNCH_ID)?->categoryId);
    }

    public function testReplayingTheSameUpdateSucceedsBecauseTheStoredItemIsExactlyWhatWeWrote(): void
    {
        $changed = self::change($this->createLunch());
        $first = $this->expenses->update(self::owner(), $changed, 1);

        $replayed = $this->expenses->update(self::owner(), $changed, 1);

        self::assertEquals($first, $replayed);
        self::assertSame(2, $this->expenses->find(self::owner(), self::LUNCH_ID)?->version);
    }

    #[DataProvider('anotherWriterAtTheNextVersion')]
    public function testAnItemAtTheNextVersionThatIsNotExactlyOursIsAConflictNotAReplay(string $date, int $cents, string $text, ?string $categoryId, string $at): void
    {
        $lunch = $this->createLunch();
        $ours = self::change($lunch);
        $this->expenses->update(self::owner(), self::change($lunch, $date, $cents, $text, $categoryId, $at), 1);

        $this->expectException(StaleVersion::class);

        $this->expenses->update(self::owner(), $ours, 1);
    }

    /**
     * @return array<string, array{string, int, string, ?string, string}>
     */
    public static function anotherWriterAtTheNextVersion(): array
    {
        return [
            'another date' => ['2026-10-22', 900, 'Coffee', null, self::LATER],
            'another amount' => ['2026-10-21', 901, 'Coffee', null, self::LATER],
            'another description' => ['2026-10-21', 900, 'Tea', null, self::LATER],
            'another category' => ['2026-10-21', 900, 'Coffee', self::CATEGORY_ID, self::LATER],
            'another update time' => ['2026-10-21', 900, 'Coffee', null, '2026-10-24T11:00:01+00:00'],
        ];
    }

    public function testAnUpdatedExpenseThatDoesNotFollowTheExpectedVersionIsRefusedBeforeAnyWrite(): void
    {
        $lunch = $this->createLunch();

        try {
            $this->expenses->update(self::owner(), self::change($lunch), 5);
            self::fail('A version that does not follow the expected one is a caller bug.');
        } catch (InvalidArgumentException) {
            self::assertEquals($lunch, $this->expenses->find(self::owner(), self::LUNCH_ID));
        }
    }

    // delete

    private function seedReceipt(string $expenseId, string $userId = self::USER): void
    {
        $this->store->putItem(['PK' => 'USER#' . $userId, 'SK' => 'RCPT#' . $expenseId, 'expenseId' => $expenseId, 'objectKey' => 'invented/receipt-' . $expenseId]);
    }

    private function receiptExists(string $expenseId, string $userId = self::USER): bool
    {
        return $this->store->getItem(['PK' => 'USER#' . $userId, 'SK' => 'RCPT#' . $expenseId]) !== null;
    }

    public function testDeleteRemovesTheExpenseAndItsIndexEntry(): void
    {
        $this->createLunch();
        $this->expenses->create(self::owner(), self::expense(self::TRAIN_ID, text: 'Train'));

        $this->expenses->delete(self::owner(), self::LUNCH_ID);

        self::assertNull($this->expenses->find(self::owner(), self::LUNCH_ID));
        self::assertSame([self::TRAIN_ID], $this->idsInRange('2026-10-01', '2026-10-31'));
    }

    public function testDeleteOfAMissingExpenseGivesExpenseNotFoundAndLeavesTheReceiptRecordAlone(): void
    {
        $this->seedReceipt(self::TRAIN_ID);

        try {
            $this->expenses->delete(self::owner(), self::TRAIN_ID);
            self::fail('Deleting a missing expense must report it.');
        } catch (ExpenseNotFound) {
            self::assertTrue($this->receiptExists(self::TRAIN_ID), 'the transaction is all or nothing');
        }
    }

    public function testDeleteAlsoRemovesTheSeededReceiptRecordOfThatExpenseOnly(): void
    {
        $this->createLunch();
        $this->expenses->create(self::owner(), self::expense(self::TRAIN_ID, text: 'Train'));
        $this->seedReceipt(self::LUNCH_ID);
        $this->seedReceipt(self::TRAIN_ID);
        $this->seedReceipt(self::LUNCH_ID, self::OTHER_USER);

        $this->expenses->delete(self::owner(), self::LUNCH_ID);

        self::assertFalse($this->receiptExists(self::LUNCH_ID), 'its own receipt record goes with it');
        self::assertTrue($this->receiptExists(self::TRAIN_ID), 'the receipt record of another expense stays');
        self::assertNotNull($this->expenses->find(self::owner(), self::TRAIN_ID));
        self::assertTrue($this->receiptExists(self::LUNCH_ID, self::OTHER_USER), 'another user\'s record stays');
    }

    public function testDeleteWithoutAReceiptRecordSucceeds(): void
    {
        $this->createLunch();

        $this->expenses->delete(self::owner(), self::LUNCH_ID);

        self::assertNull($this->expenses->find(self::owner(), self::LUNCH_ID));
    }

    public function testDeleteByAnotherUserIsExpenseNotFoundAndKeepsTheOwnersExpense(): void
    {
        $lunch = $this->createLunch();

        try {
            $this->expenses->delete(self::owner(self::OTHER_USER), self::LUNCH_ID);
            self::fail('Another user must not delete the expense.');
        } catch (ExpenseNotFound) {
            self::assertEquals($lunch, $this->expenses->find(self::owner(), self::LUNCH_ID));
        }
    }

    public function testDeleteRefusesAnIdThatIsNotAUuidBeforeAnyWrite(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expenses->delete(self::owner(), 'EXP#../PROFILE');
    }

    // pageByDate

    /**
     * Four expenses in October, newest first: LUNCH (20th), TRAIN (18th), COFFEE (15th), FOURTH (10th).
     *
     * @return list<string> the ids in that order
     */
    private function seedOctober(): array
    {
        $plan = [self::LUNCH_ID => '2026-10-20', self::TRAIN_ID => '2026-10-18', self::COFFEE_ID => '2026-10-15', self::FOURTH_ID => '2026-10-10'];
        foreach ($plan as $id => $date) {
            $this->expenses->create(self::owner(), self::expense($id, $date));
        }

        return array_keys($plan);
    }

    /**
     * @param list<Expense> $items
     *
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        return array_map(static fn(Expense $expense): string => $expense->id, $items);
    }

    /**
     * @param array{SK: string, LSI1SK: string}|null $startKey
     */
    private function october(?array $startKey = null, int $limit = 100): ExpenseBatch
    {
        return $this->expenses->pageByDate(self::owner(), self::date('2026-10-01'), self::date('2026-10-31'), $startKey, $limit);
    }

    public function testPageByDateReturnsTheRangeNewestFirstWithBothEndDaysIncluded(): void
    {
        $ids = $this->seedOctober();
        $this->expenses->create(self::owner(), self::expense(self::FIFTH_ID, '2026-09-30'));
        $this->expenses->create(self::owner(), self::expense('f6071829-3a4b-4c5d-8e6f-708192031425', '2026-11-01'));

        $batch = $this->expenses->pageByDate(self::owner(), self::date('2026-10-10'), self::date('2026-10-20'), null, 100);

        self::assertSame($ids, self::ids($batch->items));
        self::assertEquals(self::expense(self::LUNCH_ID, '2026-10-20'), $batch->items[0]);
    }

    public function testPageByDateOrdersExpensesOfOneDayByIdDescending(): void
    {
        foreach ([self::COFFEE_ID, self::LUNCH_ID, self::TRAIN_ID] as $id) {
            $this->expenses->create(self::owner(), self::expense($id, '2026-10-20'));
        }

        $ids = self::ids($this->october()->items);

        $expected = [self::COFFEE_ID, self::LUNCH_ID, self::TRAIN_ID];
        rsort($expected);
        self::assertSame($expected, $ids);
    }

    public function testPageByDateStopsAtTheBatchLimitAndContinuesFromTheLastKeyUntilTheEnd(): void
    {
        $ids = $this->seedOctober();

        $first = $this->october(null, 3);
        self::assertSame(array_slice($ids, 0, 3), self::ids($first->items));
        self::assertSame(['SK' => 'EXP#' . $ids[2], 'LSI1SK' => 'EXP#2026-10-15#' . $ids[2]], $first->lastKey);

        $second = $this->october($first->lastKey, 3);
        self::assertSame([$ids[3]], self::ids($second->items));
        self::assertNull($second->lastKey);
    }

    public function testPageByDateWithALimitEqualToTheRemainingCountGivesAKeyThenAnEmptyBatch(): void
    {
        $ids = $this->seedOctober();
        $first = $this->october(null, 2);
        $second = $this->october($first->lastKey, 2);
        self::assertSame(array_slice($ids, 2, 2), self::ids($second->items));
        self::assertNotNull($second->lastKey, 'the limit was reached, so there may be more');

        $third = $this->october($second->lastKey, 2);

        self::assertSame([], $third->items);
        self::assertNull($third->lastKey);
    }

    public function testPageByDateOfAnEmptyRangeIsEmptyWithNoKey(): void
    {
        $batch = $this->october();

        self::assertSame([], $batch->items);
        self::assertNull($batch->lastKey);
    }

    public function testPageByDateReturnsOnlyTheCallersExpensesNeverAProfileACategoryALockOrAReceiptRecord(): void
    {
        $this->createLunch();
        $theirs = Expense::new(self::TRAIN_ID, self::date('2026-10-20'), self::amount(5), self::description('Theirs'), null, new DateTimeImmutable(self::NOW));
        $this->expenses->create(self::owner(self::OTHER_USER), $theirs);
        $mine = 'USER#' . self::USER;
        $this->store->putItem(['PK' => $mine, 'SK' => 'PROFILE', 'userId' => self::USER, 'passwordHash' => 'invented-hash']);
        $this->store->putItem(['PK' => $mine, 'SK' => 'CAT#' . self::CATEGORY_ID, 'categoryId' => self::CATEGORY_ID, 'name' => 'Food']);
        $this->store->putItem(['PK' => $mine, 'SK' => 'CATNAME#' . str_repeat('a', 64), 'categoryId' => self::CATEGORY_ID]);
        $this->seedReceipt(self::LUNCH_ID);

        self::assertSame([self::LUNCH_ID], self::ids($this->october()->items));
    }

    public function testPageByDateRefusesABadRangeLimitOrStartKey(): void
    {
        $this->seedOctober();
        $coffee = ['SK' => 'EXP#' . self::COFFEE_ID, 'LSI1SK' => 'EXP#2026-10-15#' . self::COFFEE_ID];
        /** @var array<string, array{callable(): mixed, class-string<InvalidArgumentException>}> $badCalls */
        $badCalls = [
            'from after to' => [fn() => $this->expenses->pageByDate(self::owner(), self::date('2026-10-31'), self::date('2026-10-01'), null, 10), InvalidArgumentException::class],
            'limit zero' => [fn() => $this->october(null, 0), InvalidArgumentException::class],
            'limit too large' => [fn() => $this->october(null, ItemStore::MAX_QUERY_LIMIT + 1), InvalidArgumentException::class],
            'SK of another kind' => [fn() => $this->october(['SK' => 'PROFILE', 'LSI1SK' => $coffee['LSI1SK']]), InvalidStartKey::class],
            'LSI1SK with another id' => [fn() => $this->october(['SK' => $coffee['SK'], 'LSI1SK' => 'EXP#2026-10-15#' . self::LUNCH_ID]), InvalidStartKey::class],
            'LSI1SK with a bad date' => [fn() => $this->october(['SK' => $coffee['SK'], 'LSI1SK' => 'EXP#2026-13-45#' . self::COFFEE_ID]), InvalidStartKey::class],
            'an extra partition member' => [fn() => $this->october(['PK' => 'USER#' . self::OTHER_USER] + $coffee), InvalidStartKey::class],
            'SK with a bad id' => [fn() => $this->october(['SK' => 'EXP#not-a-uuid', 'LSI1SK' => 'EXP#2026-10-15#not-a-uuid']), InvalidStartKey::class],
            'start key dated before from' => [fn() => $this->october(['SK' => $coffee['SK'], 'LSI1SK' => 'EXP#2026-09-30#' . self::COFFEE_ID]), InvalidStartKey::class],
            'start key dated after to' => [fn() => $this->october(['SK' => $coffee['SK'], 'LSI1SK' => 'EXP#2026-11-01#' . self::COFFEE_ID]), InvalidStartKey::class],
        ];

        foreach ($badCalls as $label => [$call, $class]) {
            try {
                $call();
                self::fail("$label must be refused.");
            } catch (InvalidArgumentException $refused) {
                self::assertSame($class, $refused::class, $label);
            }
        }
    }

    public function testPageByDateWithACorruptItemInTheRangeIsAStorageFailureNamingTheQuery(): void
    {
        $this->seedOctober();
        $this->store->putItem([
            'PK' => 'USER#' . self::USER, 'SK' => 'EXP#' . self::FIFTH_ID, 'LSI1SK' => 'EXP#2026-10-12#' . self::FIFTH_ID, 'expenseId' => self::FIFTH_ID,
            'date' => '2026-10-12', 'amountCents' => '1250', 'description' => 'Corrupt', 'version' => 1, 'createdAt' => '2026-10-23T10:00:00Z', 'updatedAt' => '2026-10-23T10:00:00Z',
        ]);

        try {
            $this->october();
            self::fail('A corrupt item must fail the page.');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
            self::assertSame(ItemStore::QUERY_OPERATION, $failure->operation);
        }
    }

    // R1: a start key whose item was deleted or re-dated between two pages (plan R1, risk R1)

    public function testR1DeletingTheStartKeyItemBetweenPagesSkipsNothingAndRepeatsNothing(): void
    {
        $ids = $this->seedOctober();
        $first = $this->october(null, 2);
        self::assertSame(array_slice($ids, 0, 2), self::ids($first->items));
        self::assertSame($ids[1], substr($first->lastKey['SK'] ?? '', 4));

        $this->expenses->delete(self::owner(), $ids[1]);
        $second = $this->october($first->lastKey, 2);

        self::assertSame([$ids[2], $ids[3]], self::ids($second->items));
    }

    public function testR1ReDatingTheStartKeyItemToANewerDayBetweenPagesSkipsNothingAndRepeatsNothing(): void
    {
        $ids = $this->seedOctober();
        $first = $this->october(null, 2);
        $startItem = $this->expenses->find(self::owner(), $ids[1]);
        self::assertNotNull($startItem);

        $this->expenses->update(self::owner(), $startItem->updated(self::date('2026-10-25'), $startItem->amount, $startItem->description, null, new DateTimeImmutable(self::LATER)), 1);
        $second = $this->october($first->lastKey, 2);

        self::assertSame([$ids[2], $ids[3]], self::ids($second->items), 'the moved item is before the old position, so it is not listed again');
    }

    public function testR1ReDatingTheStartKeyItemToAnOlderDayMovesItToItsNewPlace(): void
    {
        $ids = $this->seedOctober();
        $first = $this->october(null, 2);
        $startItem = $this->expenses->find(self::owner(), $ids[1]);
        self::assertNotNull($startItem);

        $this->expenses->update(self::owner(), $startItem->updated(self::date('2026-10-05'), $startItem->amount, $startItem->description, null, new DateTimeImmutable(self::LATER)), 1);
        $second = $this->october($first->lastKey, 100);

        self::assertSame([$ids[2], $ids[3], $ids[1]], self::ids($second->items), 'nothing skipped; the moved item appears once, at its new place');
        self::assertNull($second->lastKey);
    }

    // corrupt items: a stored expense that does not have the shape this code wrote is a storage failure, never a guess

    /**
     * @param array<string, mixed> $override a null value removes the attribute
     *
     * @return array<string, mixed>
     */
    private static function rawExpense(array $override): array
    {
        $item = $override + [
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

        $present = array_filter($item, static fn(mixed $value): bool => $value !== null);

        // NULL_TYPE stands for an attribute stored with the DynamoDB type NULL (a plain null removes it).
        return array_map(static fn(mixed $value): mixed => $value === self::NULL_TYPE ? null : $value, $present);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('corruptOverrides')]
    public function testACorruptStoredItemIsACorruptItemFailureNamingTheRealOperation(array $override): void
    {
        $this->store->putItem(self::rawExpense($override));

        try {
            $this->expenses->find(self::owner(), self::LUNCH_ID);
            self::fail('A corrupt item must not be read.');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
            self::assertSame('GetItem', $failure->operation);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function corruptOverrides(): array
    {
        return [
            'amount is a string' => [['amountCents' => '1250']],
            'amount has cents' => [['amountCents' => 12.5]],
            'amount is zero' => [['amountCents' => 0]],
            'amount is above the maximum' => [['amountCents' => Amount::MAX_CENTS + 1]],
            'amount is missing' => [['amountCents' => null]],
            'date is not a day' => [['date' => '2026-02-30']],
            'date is before the minimum' => [['date' => '1999-12-31', 'LSI1SK' => 'EXP#1999-12-31#' . self::LUNCH_ID]],
            'date is missing' => [['date' => null]],
            'date is a number' => [['date' => 20261020]],
            'index key disagrees with the date' => [['LSI1SK' => 'EXP#2026-10-21#' . self::LUNCH_ID]],
            'index key disagrees with the id' => [['LSI1SK' => 'EXP#2026-10-20#' . self::TRAIN_ID]],
            'index key is missing' => [['LSI1SK' => null]],
            'expense id disagrees with the sort key' => [['expenseId' => self::TRAIN_ID]],
            'expense id is missing' => [['expenseId' => null]],
            'description is empty' => [['description' => '']],
            'description has surrounding space' => [['description' => ' Lunch']],
            'description has a control character' => [['description' => "Lun\x00ch"]],
            'description is too long' => [['description' => str_repeat('a', 201)]],
            'description is a number' => [['description' => 5]],
            'description is missing' => [['description' => null]],
            'category id is upper case' => [['categoryId' => strtoupper(self::CATEGORY_ID)]],
            'category id is not a uuid' => [['categoryId' => 'food']],
            'category id is empty' => [['categoryId' => '']],
            'category id has the NULL type' => [['categoryId' => self::NULL_TYPE]],
            'category id is a number' => [['categoryId' => 7]],
            'version is zero' => [['version' => 0]],
            'version is a string' => [['version' => '1']],
            'version has a fraction' => [['version' => 1.5]],
            'version is missing' => [['version' => null]],
            'created time has no zone' => [['createdAt' => '2026-10-23T10:00:00']],
            'created time has a fraction' => [['createdAt' => '2026-10-23T10:00:00.5Z']],
            'created time is a number' => [['createdAt' => 5]],
            'updated time is missing' => [['updatedAt' => null]],
            'updated time is not a time' => [['updatedAt' => 'yesterday']],
        ];
    }
}
