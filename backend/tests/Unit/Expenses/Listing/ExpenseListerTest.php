<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Listing;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryName;
use App\Categories\CategoryResolver;
use App\Expenses\Expense;
use App\Expenses\InvalidStartKey;
use App\Expenses\Listing\ExpenseFilter;
use App\Expenses\Listing\ExpenseLister;
use App\Expenses\Listing\ExpensePage;
use App\Expenses\Listing\InvalidQuery;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Tests\Support\CountingCategoryLister;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\FakeExpenseRangeReader;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The page engine (B18) over a fake reader: when it stops, what cursor it hands out, how big its batches are. Ids,
 * descriptions and dates are invented.
 */
final class ExpenseListerTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const UNKNOWN_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';

    private static function owner(): Principal
    {
        return new Principal(self::USER, str_repeat('a', 64));
    }

    private static function categories(?CountingCategoryLister $lister = null): CategoryResolver
    {
        $name = CategoryName::parse('Food');
        self::assertInstanceOf(CategoryName::class, $name);
        $lister ??= new CountingCategoryLister([Category::new(self::FOOD_ID, $name, new DateTimeImmutable('2026-10-23T10:00:00+00:00'))]);

        return new CategoryResolver($lister, self::owner());
    }

    /**
     * Expenses 1..$count, newest first (the highest id first, all on one day, which is how LSI1 orders them).
     *
     * @param callable(int): array{string, ?string}|null $describe n => [description, categoryId]
     *
     * @return list<Expense>
     */
    private static function expenses(int $count, ?callable $describe = null): array
    {
        $items = [];
        for ($n = $count; $n >= 1; --$n) {
            [$text, $categoryId] = $describe === null ? ['Lunch ' . $n, null] : $describe($n);
            $items[] = ExpenseBuilder::expense($n, '2026-10-20', $text, $categoryId);
        }

        return $items;
    }

    /** @param array<array-key, mixed> $query */
    private static function page(FakeExpenseRangeReader $reader, array $query, ?CategoryResolver $categories = null): ExpensePage
    {
        $categories ??= self::categories();
        $filter = ExpenseFilter::fromQuery($query);

        return (new ExpenseLister($reader))->list(self::owner(), $filter, $categories);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function cursorKey(ExpensePage $page, array $query): string
    {
        self::assertNotNull($page->nextCursor);
        $next = ExpenseFilter::fromQuery(['cursor' => $page->nextCursor] + $query);
        self::assertNotNull($next->cursor);

        return $next->cursor->startKey()['SK'];
    }

    /**
     * @param list<Expense> $items
     *
     * @return list<int>
     */
    private static function numbers(array $items): array
    {
        return array_map(static fn(Expense $e): int => (int) hexdec(substr($e->id, 0, 8)), $items);
    }

    public function testItStopsAtTheLimitAndHandsOutACursorAtTheLastItem(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(30));

        $page = self::page($reader, ['limit' => '5']);

        self::assertSame([30, 29, 28, 27, 26], self::numbers($page->items));
        self::assertSame('EXP#' . ExpenseBuilder::uuid(26), self::cursorKey($page, ['limit' => '5']));
        self::assertCount(1, $reader->calls);
    }

    public function testTheNextPageContinuesAfterTheCursor(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(12));
        $first = self::page($reader, ['limit' => '5']);

        $second = self::page($reader, ['limit' => '5', 'cursor' => (string) $first->nextCursor]);
        $third = self::page($reader, ['limit' => '5', 'cursor' => (string) $second->nextCursor]);

        self::assertSame([7, 6, 5, 4, 3], self::numbers($second->items));
        self::assertSame([2, 1], self::numbers($third->items));
        self::assertNull($third->nextCursor);
    }

    public function testWithoutAPhpFilterTheBatchSizeFollowsTheLimit(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(30));

        self::page($reader, ['limit' => '7']);
        self::page($reader, []);
        self::page($reader, ['from' => '2026-01-01', 'to' => '2026-12-31', 'limit' => '100']);

        self::assertSame([7, 50, 100], array_column($reader->calls, 'batchLimit'));
    }

    public function testWithAPhpFilterEveryBatchIsFull(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(30));

        self::page($reader, ['limit' => '2', 'q' => 'lunch']);
        self::page($reader, ['limit' => '2', 'category' => 'none']);
        self::page($reader, ['limit' => '2', 'category' => self::FOOD_ID]);

        self::assertSame([100, 100, 100], array_column($reader->calls, 'batchLimit'));
    }

    public function testAStopInTheMiddleOfABatchBuildsTheCursorFromTheLastExaminedItemNotTheBatchKey(): void
    {
        // Every 7th item matches. Items are examined from 150 downward; the 3rd match is item 150-20=130 (150, 143, 136).
        $query = ['limit' => '3', 'q' => 'match'];
        $everySeventh = static fn(int $n): array => [$n % 7 === 150 % 7 ? 'a match' : 'other', null];
        $reader = new FakeExpenseRangeReader(self::expenses(150, $everySeventh));

        $page = self::page($reader, $query);

        self::assertSame([150, 143, 136], self::numbers($page->items));
        self::assertSame('EXP#' . ExpenseBuilder::uuid(136), self::cursorKey($page, $query), 'not item 51, the last of the batch');
        self::assertCount(1, $reader->calls);
    }

    public function testTheCursorOfAMidBatchStopResumesWithoutSkippingOrRepeating(): void
    {
        $everySeventh = static fn(int $n): array => [$n % 7 === 0 ? 'a match' : 'other', null];
        $reader = new FakeExpenseRangeReader(self::expenses(150, $everySeventh));
        $query = ['limit' => '4', 'q' => 'match'];
        $seen = [];

        $page = self::page($reader, $query);
        $seen = [...$seen, ...self::numbers($page->items)];
        for ($round = 0; $round < 10 && $page->nextCursor !== null; ++$round) {
            $page = self::page($reader, ['cursor' => $page->nextCursor] + $query);
            $seen = [...$seen, ...self::numbers($page->items)];
        }

        self::assertSame(range(147, 7, -7), $seen);
        self::assertNull($page->nextCursor);
    }

    public function testItStopsAfterOneThousandExaminedItemsWithACursorAtTheThousandth(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(1050));
        $query = ['q' => 'no such text', 'limit' => '100'];

        $page = self::page($reader, $query);

        self::assertSame([], $page->items);
        self::assertCount(10, $reader->calls);
        self::assertSame(array_fill(0, 10, 100), array_column($reader->calls, 'batchLimit'));
        self::assertSame('EXP#' . ExpenseBuilder::uuid(51), self::cursorKey($page, $query), 'the 1000th examined of 1050, newest first');
    }

    public function testTheBudgetStopKeepsTheMatchesFoundSoFar(): void
    {
        $matchesAtTheFarEnd = static fn(int $n): array => [$n % 400 === 0 ? 'a match' : 'other', null];
        $reader = new FakeExpenseRangeReader(self::expenses(1050, $matchesAtTheFarEnd));

        $page = self::page($reader, ['q' => 'match']);

        self::assertSame([800, 400], self::numbers($page->items));
        self::assertNotNull($page->nextCursor);
    }

    public function testARangeThatEndsInsideTheLastBudgetBatchGivesNoCursor(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(950));

        $page = self::page($reader, ['q' => 'no such text']);

        self::assertNull($page->nextCursor);
        self::assertCount(10, $reader->calls);
    }

    public function testTheEndOfTheRangeGivesNoCursor(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(12));

        $page = self::page($reader, []);

        self::assertCount(12, $page->items);
        self::assertNull($page->nextCursor);
    }

    public function testTheLimitthMatchOnTheLastItemOfTheLastBatchGivesNoCursor(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(5));

        $page = self::page($reader, ['limit' => '5', 'q' => 'lunch']);

        self::assertCount(5, $page->items);
        self::assertNull($page->nextCursor);
    }

    public function testAFullBatchThatEndsAtTheLimitStillHandsOutACursorAndTheNextPageIsEmpty(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(5));
        $first = self::page($reader, ['limit' => '5']);

        $second = self::page($reader, ['limit' => '5', 'cursor' => (string) $first->nextCursor]);

        self::assertNotNull($first->nextCursor);
        self::assertSame([], $second->items);
        self::assertNull($second->nextCursor);
    }

    public function testAnEmptyStoreGivesAnEmptyPage(): void
    {
        $page = self::page(new FakeExpenseRangeReader([]), []);

        self::assertSame([], $page->items);
        self::assertNull($page->nextCursor);
    }

    public function testAnUnknownCategoryReadsNoExpensesAtAll(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(10));

        $page = self::page($reader, ['category' => self::UNKNOWN_ID]);

        self::assertSame([], $page->items);
        self::assertNull($page->nextCursor);
        self::assertSame([], $reader->calls);
    }

    public function testCategoryNoneKeepsUncategorizedAndLeftoverIds(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(6, static fn(int $n): array => ['Lunch', match ($n % 3) {
            0 => null,
            1 => self::UNKNOWN_ID,
            default => self::FOOD_ID,
        }]));

        $page = self::page($reader, ['category' => 'none']);

        self::assertSame([6, 4, 3, 1], self::numbers($page->items));
    }

    public function testACategoryIdKeepsOnlyItsExpenses(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(6, static fn(int $n): array => ['Lunch', $n % 2 === 0 ? self::FOOD_ID : null]));

        $page = self::page($reader, ['category' => self::FOOD_ID]);

        self::assertSame([6, 4, 2], self::numbers($page->items));
    }

    public function testTheStartKeyOfTheCursorIsPassedToTheReader(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(10));
        $first = self::page($reader, ['limit' => '3']);

        self::page($reader, ['limit' => '3', 'cursor' => (string) $first->nextCursor]);

        self::assertNull($reader->calls[0]['startKey']);
        self::assertSame('EXP#' . ExpenseBuilder::uuid(8), $reader->calls[1]['startKey']['SK'] ?? null);
    }

    public function testAResolverOfAnotherPrincipalIsRefusedBeforeAnyRead(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(10));
        $categoryReads = new CountingCategoryLister([]);
        $other = new Principal('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d', str_repeat('b', 64));
        $filter = ExpenseFilter::fromQuery(['category' => self::UNKNOWN_ID]);

        try {
            (new ExpenseLister($reader))->list(self::owner(), $filter, new CategoryResolver($categoryReads, $other));
            self::fail('No exception.');
        } catch (LogicException) {
            self::assertSame([], $reader->calls);
            self::assertSame(0, $categoryReads->calls);
        }
    }

    public function testAStartKeyFromTheServerThatTheReaderRefusesIsNotTheClientsFault(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(250));
        $reader->refuseFromCall = 2;

        try {
            self::page($reader, ['q' => 'no such text']);
            self::fail('No exception.');
        } catch (InvalidQuery) {
            self::fail('A key the server built was reported as a bad cursor (400).');
        } catch (InvalidStartKey) {
            self::assertCount(2, $reader->calls);
        }
    }

    public function testARefusedStartKeyWithNoCursorIsNotTheClientsFault(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(10));
        $reader->refuseFromCall = 1;

        try {
            self::page($reader, ['limit' => '3']);
            self::fail('No exception.');
        } catch (InvalidQuery) {
            self::fail('A request without a cursor was reported as a bad cursor (400).');
        } catch (InvalidStartKey) {
            self::assertCount(1, $reader->calls);
        }
    }

    public function testABatchLargerThanRequestedIsAStorageFailure(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(10));
        $reader->overDeliverBy = 1;

        try {
            self::page($reader, ['limit' => '3']);
            self::fail('No failure.');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
        }
    }

    public function testAStartKeyTheReaderRefusesIsTheCursorError(): void
    {
        $reader = new FakeExpenseRangeReader(self::expenses(10));
        $first = self::page($reader, ['limit' => '3']);
        $reader->refuseStartKey = true;

        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('The cursor is not valid for these filters.');

        self::page($reader, ['limit' => '3', 'cursor' => (string) $first->nextCursor]);
    }

    public function testAnEmptyBatchThatClaimsThereIsMoreIsAStorageFailureNotALoop(): void
    {
        $reader = new FakeExpenseRangeReader([]);
        $reader->answerEmptyBatchWithKey = true;

        try {
            self::page($reader, ['q' => 'x']);
            self::fail('No failure.');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
        }
        self::assertCount(1, $reader->calls);
    }

    public function testAStorageFailureOfTheCategoryReadEndsTheRequestOnTheFirstItem(): void
    {
        $lister = new CountingCategoryLister([]);
        $lister->failures = 5;
        $reader = new FakeExpenseRangeReader(self::expenses(10, static fn(int $n): array => ['Lunch', self::UNKNOWN_ID]));

        try {
            self::page($reader, ['category' => 'none'], self::categories($lister));
            self::fail('No failure.');
        } catch (StorageFailure) {
            self::addToAssertionCount(1);
        }
        self::assertSame(1, $lister->calls, 'the resolver is asked once, not once per item');
    }
}
