<?php

declare(strict_types=1);

namespace App\Tests\Integration\Expenses;

use App\Auth\Session\Principal;
use App\Categories\CategoryRepository;
use App\Categories\CategoryResolver;
use App\Expenses\Expense;
use App\Expenses\ExpenseRepository;
use App\Expenses\Listing\ExpenseFilter;
use App\Expenses\Listing\ExpenseLister;
use App\Expenses\Listing\ExpensePage;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use Aws\CommandInterface;
use Aws\Middleware;
use PHPUnit\Framework\TestCase;

/**
 * The lister over the real repository on DynamoDB Local, on a table of its own: the read budget counted at the SDK,
 * paging under concurrent writes, strong consistency, and what must never show up. Ids and texts are invented.
 */
final class ExpenseListingTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_USER = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const DELETED_CATEGORY_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const SEED_BASE = 100000;

    private TemporaryTable $table;
    private ItemStore $store;
    private ExpenseRepository $expenses;
    private ExpenseLister $lister;

    /** @var list<int> the `Limit` of every LSI1 query sent, in order */
    private array $lsi1Limits = [];

    protected function setUp(): void
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $this->table = TemporaryTable::create($client);
        $this->store = new ItemStore($client, $this->table->name);
        $this->expenses = new ExpenseRepository($this->store);

        $client->getHandlerList()->appendInit(Middleware::tap(function (CommandInterface $command): void {
            if ($command->getName() === 'Query' && $command['IndexName'] === 'LSI1') {
                $limit = $command['Limit'];
                $this->lsi1Limits[] = is_int($limit) ? $limit : -1;
            }
        }), 'record-lsi1-limits');
        $this->lister = new ExpenseLister(new ExpenseRepository(new ItemStore($client, $this->table->name)));
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    private static function owner(string $userId = self::USER): Principal
    {
        return new Principal($userId, str_repeat('a', 64));
    }

    private function resolver(string $userId = self::USER): CategoryResolver
    {
        return new CategoryResolver(new CategoryRepository($this->store), self::owner($userId));
    }

    /** @param array<array-key, string> $query */
    private function page(array $query, string $userId = self::USER): ExpensePage
    {
        $resolver = $this->resolver($userId);

        return $this->lister->list(self::owner($userId), ExpenseFilter::fromQuery($query), $resolver);
    }

    private function seed(int $n, string $date, string $text = 'Lunch', ?string $categoryId = null, string $userId = self::USER): Expense
    {
        $expense = ExpenseBuilder::expense($n, $date, $text, $categoryId);
        $this->expenses->create(self::owner($userId), $expense);

        return $expense;
    }

    private function seedMany(int $count): void
    {
        for ($n = 1; $n <= $count; ++$n) {
            $this->seed(self::SEED_BASE + $n, '2026-10-' . sprintf('%02d', 1 + $n % 28), 'Lunch ' . $n);
        }
    }

    /**
     * @param list<Expense> $items
     *
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        return array_map(static fn(Expense $e): string => $e->id, $items);
    }

    public function testAThousandAndFiftyNonMatchingItemsAreExaminedUnderTheBudgetAndGiveACursor(): void
    {
        $this->seedMany(1050);
        $this->lsi1Limits = [];

        $page = $this->page(['q' => 'text that no description has']);

        self::assertSame([], $page->items);
        self::assertNotNull($page->nextCursor);
        self::assertSame(array_fill(0, 10, 100), $this->lsi1Limits, 'ten batches of 100: at most 1,000 items examined');
    }

    public function testTheCursorOfABudgetStopContinuesWithTheRest(): void
    {
        $this->seedMany(1050);
        $query = ['q' => 'text that no description has'];
        $first = $this->page($query);

        $second = $this->page(['cursor' => (string) $first->nextCursor] + $query);

        self::assertSame([], $second->items);
        self::assertNull($second->nextCursor, 'the 50 items left are examined and the range ends');
    }

    public function testWalkingEveryPageWithInterleavedInsertsAndDeletesReturnsEachStableItemOnceNewestFirst(): void
    {
        $stable = [];
        for ($n = 1; $n <= 40; ++$n) {
            $stable[] = $this->seed($n, '2026-09-' . sprintf('%02d', 1 + $n % 5));
        }
        $doomed = [];
        for ($n = 41; $n <= 50; ++$n) {
            $doomed[] = $this->seed($n, '2026-09-' . sprintf('%02d', 1 + $n % 5));
        }

        $seen = [];
        $page = $this->page(['limit' => '7']);
        $round = 0;
        do {
            $seen = [...$seen, ...$page->items];
            // Between every two pages: one new item on each side of the cursor, and one deletion.
            $this->seed(200 + $round, '2026-12-01');
            $this->seed(300 + $round, '2026-08-0' . (1 + $round % 9));
            if (isset($doomed[$round])) {
                $this->expenses->delete(self::owner(), $doomed[$round]->id);
            }
            ++$round;
            $page = $page->nextCursor === null ? null : $this->page(['limit' => '7', 'cursor' => $page->nextCursor]);
        } while ($page !== null && $round < 40);

        $ids = self::ids($seen);
        self::assertSame($ids, array_values(array_unique($ids)), 'no item twice');
        foreach ($stable as $expense) {
            self::assertContains($expense->id, $ids, 'every item that was there throughout is returned');
        }
        $keys = array_map(static fn(Expense $e): string => $e->date->value() . '#' . $e->id, $seen);
        $sorted = $keys;
        rsort($sorted);
        self::assertSame($sorted, $keys, 'newest first, and by id within a day');
        self::assertNull($page, 'the walk reached the end of the range');
    }

    public function testAJustCreatedItemIsInTheVeryNextList(): void
    {
        $this->seed(1, '2026-10-01');
        self::assertCount(1, $this->page([])->items);

        $created = $this->seed(2, '2026-10-02');

        self::assertSame([$created->id, ExpenseBuilder::uuid(1)], self::ids($this->page([])->items));
    }

    public function testCategoryNoneIncludesLeftoverIdsAndNeverAnotherUsersItems(): void
    {
        $plain = $this->seed(1, '2026-10-01');
        $leftover = $this->seed(2, '2026-10-02', categoryId: self::DELETED_CATEGORY_ID);
        $this->seed(3, '2026-10-03', userId: self::OTHER_USER);

        $page = $this->page(['category' => 'none']);

        self::assertSame([$leftover->id, $plain->id], self::ids($page->items));
    }

    public function testAnUnknownCategoryAndAnotherUsersCategoryListNothingWithoutQueryingTheIndex(): void
    {
        $this->seed(1, '2026-10-01', categoryId: self::DELETED_CATEGORY_ID);
        $this->lsi1Limits = [];

        $page = $this->page(['category' => self::DELETED_CATEGORY_ID]);

        self::assertSame([], $page->items);
        self::assertNull($page->nextCursor);
        self::assertSame([], $this->lsi1Limits);
    }

    public function testTheProfileAndCategoryItemsOfTheUserNeverAppear(): void
    {
        $this->store->putItem(['PK' => 'USER#' . self::USER, 'SK' => 'PROFILE', 'passwordHash' => 'x']);
        $this->store->putItem(['PK' => 'USER#' . self::USER, 'SK' => 'CAT#' . self::DELETED_CATEGORY_ID, 'name' => 'Food']);
        $this->store->putItem(['PK' => 'USER#' . self::USER, 'SK' => 'CATNAME#abc', 'categoryId' => self::DELETED_CATEGORY_ID]);
        $only = $this->seed(1, '2026-10-01');

        $page = $this->page([]);

        self::assertSame([$only->id], self::ids($page->items));
    }

    public function testTheFilterByTextAndDateRangeFindsOnlyWhatMatches(): void
    {
        $this->seed(1, '2026-10-01', 'Coffee with Anna');
        $wanted = $this->seed(2, '2026-10-05', "Caf\u{00E9} lunch");
        $this->seed(3, '2026-10-06', 'Train');
        $this->seed(4, '2026-11-01', "Caf\u{00C9} dinner");

        $page = $this->page(['q' => "CAF\u{00C9}", 'from' => '2026-10-02', 'to' => '2026-10-31']);

        self::assertSame([$wanted->id], self::ids($page->items));
    }
}
