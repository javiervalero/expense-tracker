<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryName;
use App\Categories\CategoryResolver;
use App\Expenses\Expense;
use App\Expenses\ExpenseBatch;
use App\Expenses\ExpenseDate;
use App\Expenses\InvalidStartKey;
use App\Expenses\Listing\ExpenseRangeReader;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Reports\MonthlyReport;
use App\Reports\MonthlyReportBuilder;
use App\Reports\ReportMonth;
use App\Tests\Support\CountingCategoryLister;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\FakeExpenseRangeReader;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The monthly aggregation (D5, D7, D8) over a fake reader and a counting category lister. Ids, names and amounts are
 * invented.
 */
final class MonthlyReportBuilderTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_USER = '8c7d6e5f-4a3b-4c2d-9e1f-0a1b2c3d4e5f';
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const TRAVEL_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const ALPHA_ID = '2a2a2a2a-2a2a-4a2a-8a2a-2a2a2a2a2a2a';
    private const BETA_ID = '3b3b3b3b-3b3b-4b3b-8b3b-3b3b3b3b3b3b';
    private const DELETED_ID = '5a4b3c2d-1e0f-4a9b-8c7d-6e5f4a3b2c1d';
    private const FOREIGN_ID = '6f6f6f6f-6f6f-4f6f-8f6f-6f6f6f6f6f6f';
    private const DAY = '2026-10-20';

    private static function owner(): Principal
    {
        return new Principal(self::USER, str_repeat('a', 64));
    }

    private static function month(string $value = '2026-10'): ReportMonth
    {
        $month = ReportMonth::parse($value);
        self::assertInstanceOf(ReportMonth::class, $month);

        return $month;
    }

    /** @param array<string, string> $names id => name */
    private static function lister(array $names): CountingCategoryLister
    {
        $stored = [];
        foreach ($names as $id => $text) {
            $name = CategoryName::parse($text);
            self::assertInstanceOf(CategoryName::class, $name);
            $stored[] = Category::new($id, $name, new DateTimeImmutable('2026-10-23T10:00:00+00:00'));
        }

        return new CountingCategoryLister($stored);
    }

    private static function resolver(CountingCategoryLister $lister, ?Principal $owner = null): CategoryResolver
    {
        return new CategoryResolver($lister, $owner ?? self::owner());
    }

    /** One expense per entry, newest first as given: [categoryId, cents]. */
    private static function expense(int $n, ?string $categoryId, int $cents): Expense
    {
        return ExpenseBuilder::expense($n, self::DAY, 'Lunch', $categoryId, null, $cents);
    }

    /**
     * $count expenses of one cent, newest (highest id) first.
     *
     * @return list<Expense>
     */
    private static function cents(int $count, ?string $categoryId = null): array
    {
        $items = [];
        for ($n = $count; $n >= 1; --$n) {
            $items[] = self::expense($n, $categoryId, 1);
        }

        return $items;
    }

    /** @return list<array{string|null, string|null, int, int}> */
    private static function rows(MonthlyReport $report): array
    {
        $rows = [];
        foreach ($report->categories as $row) {
            $rows[] = [$row->categoryId, $row->name, $row->totalCents, $row->expenseCount];
        }

        return $rows;
    }

    /**
     * @param list<array{startKey: array<string, string>|null, batchLimit: int}> $calls
     *
     * @return list<int>
     */
    private static function limits(array $calls): array
    {
        return array_map(static fn(array $call): int => $call['batchLimit'], $calls);
    }

    public function testItReadsOnlyTheMonthOfTheOwner(): void
    {
        $reader = new FakeExpenseRangeReader([]);

        (new MonthlyReportBuilder($reader))->build(self::owner(), self::month('2024-02'), self::resolver(self::lister([])));

        self::assertSame(self::USER, $reader->lastOwnerId);
        self::assertSame('2024-02-01', $reader->lastFrom);
        self::assertSame('2024-02-29', $reader->lastTo);
    }

    public function testAForeignResolverIsRefusedBeforeAnyRead(): void
    {
        $reader = new FakeExpenseRangeReader(self::cents(3));
        $lister = self::lister([self::FOOD_ID => 'Food']);
        $foreign = self::resolver($lister, new Principal(self::OTHER_USER, str_repeat('b', 64)));

        try {
            (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), $foreign);
            self::fail('Expected a LogicException.');
        } catch (LogicException) {
            self::assertSame([], $reader->calls);
            self::assertSame(0, $lister->calls);
        }
    }

    public function testAnEmptyMonthIsAZeroReportAndReadsNoCategories(): void
    {
        $reader = new FakeExpenseRangeReader([]);
        $lister = self::lister([self::FOOD_ID => 'Food']);

        $report = (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), self::resolver($lister));

        self::assertSame('2026-10', $report->month->value());
        self::assertSame(0, $report->totalCents);
        self::assertSame(0, $report->expenseCount);
        self::assertTrue($report->complete);
        self::assertSame([], $report->categories);
        self::assertSame(0, $lister->calls);
        self::assertCount(1, $reader->calls);
    }

    public function testTotalsAreSummedExactlyPerCategory(): void
    {
        $reader = new FakeExpenseRangeReader([
            self::expense(5, self::FOOD_ID, 1_000),
            self::expense(4, self::TRAVEL_ID, 1_001),
            self::expense(3, self::FOOD_ID, 1_002),
            self::expense(2, self::TRAVEL_ID, 1_003),
            self::expense(1, null, 1_004),
        ]);
        $resolver = self::resolver(self::lister([self::FOOD_ID => 'Food', self::TRAVEL_ID => 'Travel']));

        $report = (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), $resolver);

        self::assertSame(5_010, $report->totalCents);
        self::assertSame(5, $report->expenseCount);
        self::assertTrue($report->complete);
        self::assertSame([
            [self::TRAVEL_ID, 'Travel', 2_004, 2],
            [self::FOOD_ID, 'Food', 2_002, 2],
            [null, null, 1_004, 1],
        ], self::rows($report));
    }

    public function testNoDeletedOrForeignCategoryIdsAreMergedIntoUncategorized(): void
    {
        $reader = new FakeExpenseRangeReader([
            self::expense(3, self::DELETED_ID, 400),
            self::expense(2, self::FOREIGN_ID, 300),
            self::expense(1, null, 100),
        ]);
        $resolver = self::resolver(self::lister([self::FOOD_ID => 'Food']));

        $report = (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), $resolver);

        self::assertSame([[null, null, 800, 3]], self::rows($report));
        self::assertSame(800, $report->totalCents);
    }

    public function testCategoriesWithoutExpensesInTheMonthAreOmitted(): void
    {
        $reader = new FakeExpenseRangeReader([self::expense(1, self::FOOD_ID, 250)]);
        $resolver = self::resolver(self::lister([self::FOOD_ID => 'Food', self::TRAVEL_ID => 'Travel']));

        $report = (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), $resolver);

        self::assertSame([[self::FOOD_ID, 'Food', 250, 1]], self::rows($report));
    }

    public function testRowsAreOrderedByTotalThenNameWithUncategorizedLastOnATie(): void
    {
        $reader = new FakeExpenseRangeReader([
            self::expense(6, self::BETA_ID, 500),
            self::expense(5, null, 500),
            self::expense(4, self::ALPHA_ID, 500),
            self::expense(3, self::FOOD_ID, 900),
            self::expense(2, self::TRAVEL_ID, 1),
        ]);
        // ALPHA_ID sorts before BETA_ID by id but after it by folded name: the name decides.
        $resolver = self::resolver(self::lister([
            self::TRAVEL_ID => 'travel',
            self::BETA_ID => 'beta',
            self::ALPHA_ID => 'Zulu',
            self::FOOD_ID => 'Food',
        ]));

        $report = (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), $resolver);

        self::assertSame(
            [self::FOOD_ID, self::BETA_ID, self::ALPHA_ID, null, self::TRAVEL_ID],
            array_map(static fn($row): ?string => $row->categoryId, $report->categories),
        );
    }

    public function testUncategorizedComesAfterNamedCategoriesWithTheSameTotal(): void
    {
        $reader = new FakeExpenseRangeReader([
            self::expense(2, null, 700),
            self::expense(1, self::TRAVEL_ID, 700),
        ]);
        $resolver = self::resolver(self::lister([self::TRAVEL_ID => 'Travel']));

        $report = (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), $resolver);

        self::assertSame([self::TRAVEL_ID, null], array_map(static fn($row): ?string => $row->categoryId, $report->categories));
    }

    public function testItFollowsTheContinuationKeyAcrossBatches(): void
    {
        $reader = new FakeExpenseRangeReader(self::cents(2_500));

        $report = (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), self::resolver(self::lister([])));

        self::assertSame(2_500, $report->expenseCount);
        self::assertSame(2_500, $report->totalCents);
        self::assertTrue($report->complete);
        self::assertSame([1_000, 1_000, 1_000], self::limits($reader->calls));
        self::assertNull($reader->calls[0]['startKey']);
        self::assertNotNull($reader->calls[1]['startKey']);
    }

    public function testMoreThanTheCapCountsExactlyTheNewestCapAndIsIncomplete(): void
    {
        $reader = new FakeExpenseRangeReader(self::cents(3_000));

        $report = (new MonthlyReportBuilder($reader, 2_500))->build(self::owner(), self::month(), self::resolver(self::lister([])));

        self::assertSame(2_500, $report->expenseCount);
        self::assertSame(2_500, $report->totalCents);
        self::assertFalse($report->complete);
        // The batch limit never exceeds what is left under the cap, then one probe of one item.
        self::assertSame([1_000, 1_000, 500, 1], self::limits($reader->calls));
    }

    public function testTheCapReachedWithNothingLeftIsComplete(): void
    {
        $reader = new FakeExpenseRangeReader(self::cents(2_000));

        $report = (new MonthlyReportBuilder($reader, 2_000))->build(self::owner(), self::month(), self::resolver(self::lister([])));

        self::assertSame(2_000, $report->expenseCount);
        self::assertTrue($report->complete);
        self::assertSame([1_000, 1_000, 1], self::limits($reader->calls));
    }

    public function testTheCapReachedWithoutAContinuationKeyNeedsNoProbe(): void
    {
        $reader = new class implements ExpenseRangeReader {
            public int $calls = 0;

            public function pageByDate(Principal $owner, ExpenseDate $from, ExpenseDate $to, ?array $startKey, int $batchLimit): ExpenseBatch
            {
                ++$this->calls;

                return new ExpenseBatch([ExpenseBuilder::expense(1), ExpenseBuilder::expense(2)], null);
            }
        };

        $report = (new MonthlyReportBuilder($reader, 2))->build(self::owner(), self::month(), self::resolver(self::lister([])));

        self::assertSame(2, $report->expenseCount);
        self::assertTrue($report->complete);
        self::assertSame(1, $reader->calls);
    }

    public function testASmallMonthUnderTheDefaultCapUsesOneBatch(): void
    {
        $reader = new FakeExpenseRangeReader(self::cents(3));

        $report = (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), self::resolver(self::lister([])));

        self::assertSame(3, $report->expenseCount);
        self::assertSame([1_000], self::limits($reader->calls));
    }

    public function testABatchWithTooManyItemsIsCorruptStorage(): void
    {
        $reader = new FakeExpenseRangeReader(self::cents(5));
        $reader->overDeliverBy = 1;

        $this->expectException(StorageFailure::class);
        $this->expectExceptionMessage(StorageFailure::CORRUPT_ITEM);
        (new MonthlyReportBuilder($reader, 3))->build(self::owner(), self::month(), self::resolver(self::lister([])));
    }

    public function testAnEmptyBatchWithAContinuationKeyIsCorruptStorage(): void
    {
        $reader = new FakeExpenseRangeReader([]);
        $reader->answerEmptyBatchWithKey = true;

        $this->expectException(StorageFailure::class);
        $this->expectExceptionMessage(StorageFailure::CORRUPT_ITEM);
        (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), self::resolver(self::lister([])));
    }

    public function testAStorageFailureOfTheReaderIsNotCaught(): void
    {
        $reader = new class implements ExpenseRangeReader {
            public function pageByDate(Principal $owner, ExpenseDate $from, ExpenseDate $to, ?array $startKey, int $batchLimit): ExpenseBatch
            {
                throw new StorageFailure('Query', 'ProvisionedThroughputExceededException');
            }
        };

        $this->expectException(StorageFailure::class);
        (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), self::resolver(self::lister([])));
    }

    public function testAKeyRefusedFromOurOwnBatchIsNotCaught(): void
    {
        $reader = new FakeExpenseRangeReader(self::cents(1_500));
        $reader->refuseFromCall = 2;

        $this->expectException(InvalidStartKey::class);
        (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), self::resolver(self::lister([])));
    }

    public function testAStorageFailureOfTheResolverIsNotCaught(): void
    {
        $reader = new FakeExpenseRangeReader([self::expense(1, self::FOOD_ID, 100)]);
        $lister = self::lister([self::FOOD_ID => 'Food']);
        $lister->failures = 1;

        $this->expectException(StorageFailure::class);
        (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), self::resolver($lister));
    }

    public function testTheResolverReadsCategoriesAtMostOnce(): void
    {
        $reader = new FakeExpenseRangeReader([
            self::expense(4, self::FOOD_ID, 100),
            self::expense(3, self::TRAVEL_ID, 100),
            self::expense(2, self::FOOD_ID, 100),
            self::expense(1, null, 100),
        ]);
        $lister = self::lister([self::FOOD_ID => 'Food', self::TRAVEL_ID => 'Travel']);

        (new MonthlyReportBuilder($reader))->build(self::owner(), self::month(), self::resolver($lister));

        self::assertSame(1, $lister->calls);
        self::assertSame([self::USER], $lister->owners);
    }
}
