<?php

declare(strict_types=1);

namespace App\Tests\Integration\DynamoDb;

use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\DynamoDb\TransactionOperation;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use PHPUnit\Framework\TestCase;

/**
 * ItemStore against DynamoDB Local, on a table of its own (random name, created from infra/dynamodb/table.json).
 */
final class ItemStoreTest extends TestCase
{
    private TemporaryTable $table;
    private ItemStore $store;

    protected function setUp(): void
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $this->table = TemporaryTable::create($client);
        $this->store = new ItemStore($client, $this->table->name);
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    /**
     * @return array<string, mixed>
     */
    private static function expense(string $user, string $id, string $date, int $cents = 100): array
    {
        return [
            TableLayout::PK => 'USER#' . $user,
            TableLayout::SK => 'EXP#' . $id,
            TableLayout::LSI1SK => 'EXP#' . $date . '#' . $id,
            'amountCents' => $cents,
        ];
    }

    public function testRoundTripKeepsIntegersUnicodeAndEmptyStrings(): void
    {
        $item = [
            TableLayout::PK => 'USER#1',
            TableLayout::SK => 'EXP#a',
            'amountCents' => 123456789012,
            'zero' => 0,
            'negative' => -5,
            'description' => 'Café 日本語 🍣 "quoted" \' ; DROP TABLE',
            'note' => '',
            'tags' => ['a', 'b'],
        ];

        $this->store->putItem($item);

        $read = $this->store->getItem([TableLayout::PK => 'USER#1', TableLayout::SK => 'EXP#a']);

        // DynamoDB does not keep attribute order. assertSame is strict about types: 123456789012 must come back as
        // an int (not a float or a string) and '' as an empty string.
        self::assertNotNull($read);
        ksort($read);
        ksort($item);
        self::assertSame($item, $read);
    }

    public function testGetItemOfAMissingItemIsNull(): void
    {
        self::assertNull($this->store->getItem([TableLayout::PK => 'USER#1', TableLayout::SK => 'NOPE']));
    }

    public function testConditionalPutOnAnExistingItemRaisesConditionFailed(): void
    {
        $item = [TableLayout::PK => 'USER#1', TableLayout::SK => 'PROFILE', 'name' => 'first'];
        $this->store->putItem($item, 'attribute_not_exists(#pk)', ['#pk' => TableLayout::PK]);

        $this->expectException(ConditionFailed::class);
        try {
            $this->store->putItem([...$item, 'name' => 'second'], 'attribute_not_exists(#pk)', ['#pk' => TableLayout::PK]);
        } finally {
            self::assertSame('first', $this->store->getItem([TableLayout::PK => 'USER#1', TableLayout::SK => 'PROFILE'])['name'] ?? null);
        }
    }

    public function testUpdateReturnsTheNewItem(): void
    {
        $this->store->putItem(self::expense('1', 'a', '2026-01-01'));

        $updated = $this->store->updateItem(
            [TableLayout::PK => 'USER#1', TableLayout::SK => 'EXP#a'],
            'SET #amount = :amount, #date = :date, #idx = :idx',
            ['#amount' => 'amountCents', '#date' => 'date', '#idx' => TableLayout::LSI1SK, '#pk' => TableLayout::PK],
            [':amount' => 999, ':date' => '2026-02-03', ':idx' => 'EXP#2026-02-03#a'],
            'attribute_exists(#pk)',
        );

        self::assertSame(999, $updated['amountCents']);
        self::assertSame('EXP#2026-02-03#a', $updated[TableLayout::LSI1SK]);
    }

    public function testUpdateOfAMissingItemWithAttributeExistsRaisesConditionFailed(): void
    {
        $this->expectException(ConditionFailed::class);

        $this->store->updateItem(
            [TableLayout::PK => 'USER#1', TableLayout::SK => 'EXP#missing'],
            'SET #a = :a',
            ['#a' => 'amountCents', '#pk' => TableLayout::PK],
            [':a' => 1],
            'attribute_exists(#pk)',
        );
    }

    public function testDeleteRemovesTheItemAndAConditionalDeleteOfAMissingOneFails(): void
    {
        $key = [TableLayout::PK => 'USER#1', TableLayout::SK => 'EXP#a'];
        $this->store->putItem(self::expense('1', 'a', '2026-01-01'));

        $this->store->deleteItem($key, 'attribute_exists(#pk)', ['#pk' => TableLayout::PK]);

        self::assertNull($this->store->getItem($key));
        $this->expectException(ConditionFailed::class);
        $this->store->deleteItem($key, 'attribute_exists(#pk)', ['#pk' => TableLayout::PK]);
    }

    public function testLsi1RangeQueryIsDescendingAndConsistent(): void
    {
        foreach ([['a', '2026-01-05'], ['b', '2026-01-20'], ['c', '2026-01-31'], ['d', '2026-02-01'], ['e', '2025-12-31']] as [$id, $date]) {
            $this->store->putItem(self::expense('1', $id, $date));
        }

        $page = $this->store->query(
            '#pk = :pk AND #idx BETWEEN :from AND :to',
            ['#pk' => TableLayout::PK, '#idx' => TableLayout::LSI1SK],
            [':pk' => 'USER#1', ':from' => 'EXP#2026-01-01', ':to' => 'EXP#2026-01-31~'],
            indexName: TableLayout::LSI1,
            scanForward: false,
        );

        self::assertSame(['EXP#c', 'EXP#b', 'EXP#a'], array_column($page->items, TableLayout::SK));
        self::assertNull($page->lastEvaluatedKey);
    }

    public function testPagingWithLimitAndExclusiveStartKeyVisitsEveryItemOnce(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $id) {
            $this->store->putItem(self::expense('1', $id, '2026-03-0' . ($i + 1)));
        }
        $seen = [];
        $startKey = null;
        $pages = 0;

        do {
            $page = $this->store->query(
                '#pk = :pk AND #idx BETWEEN :from AND :to',
                ['#pk' => TableLayout::PK, '#idx' => TableLayout::LSI1SK],
                [':pk' => 'USER#1', ':from' => 'EXP#2026-03-01', ':to' => 'EXP#2026-03-31~'],
                indexName: TableLayout::LSI1,
                limit: 2,
                exclusiveStartKey: $startKey,
            );
            array_push($seen, ...array_column($page->items, TableLayout::SK));
            $startKey = $page->lastEvaluatedKey;
            ++$pages;
        } while ($startKey !== null && $pages < 10);

        self::assertSame(['EXP#a', 'EXP#b', 'EXP#c', 'EXP#d', 'EXP#e'], $seen);
        self::assertSame(3, $pages);
    }

    public function testQueryByBaseKeyWithBeginsWith(): void
    {
        $this->store->putItem([TableLayout::PK => 'USER#1', TableLayout::SK => 'CAT#x']);
        $this->store->putItem([TableLayout::PK => 'USER#1', TableLayout::SK => 'CAT#y']);
        $this->store->putItem(self::expense('1', 'a', '2026-01-01'));

        $page = $this->store->query(
            '#pk = :pk AND begins_with(#sk, :prefix)',
            ['#pk' => TableLayout::PK, '#sk' => TableLayout::SK],
            [':pk' => 'USER#1', ':prefix' => 'CAT#'],
        );

        self::assertSame(['CAT#x', 'CAT#y'], array_column($page->items, TableLayout::SK));
    }

    public function testAQueryNeverReturnsAnotherPartitionsItems(): void
    {
        $this->store->putItem(self::expense('1', 'a', '2026-01-10'));
        $this->store->putItem(self::expense('2', 'b', '2026-01-11'));
        $this->store->putItem(self::expense('12', 'c', '2026-01-12'));

        foreach ([null, TableLayout::LSI1] as $index) {
            $page = $this->store->query(
                '#pk = :pk',
                ['#pk' => TableLayout::PK],
                [':pk' => 'USER#1'],
                indexName: $index,
            );

            self::assertSame(['EXP#a'], array_column($page->items, TableLayout::SK));
        }
    }

    public function testValuesAreNeverInterpolatedSoInjectionLookingInputIsJustData(): void
    {
        $this->store->putItem([TableLayout::PK => 'USER#1', TableLayout::SK => 'CAT#x']);

        $page = $this->store->query(
            '#pk = :pk',
            ['#pk' => TableLayout::PK],
            [':pk' => "USER#1' OR #pk <> :pk"],
        );

        self::assertSame([], $page->items);
    }

    public function testUserPlusEmailLockTransactionSucceedsThenIsCancelledWithReasons(): void
    {
        $operations = static fn(string $userId): array => [
            TransactionOperation::put(
                [TableLayout::PK => 'USER#' . $userId, TableLayout::SK => 'PROFILE', 'email' => 'ada@example.com'],
                'attribute_not_exists(#pk)',
                ['#pk' => TableLayout::PK],
            ),
            TransactionOperation::put(
                [TableLayout::PK => 'EMAIL#ada@example.com', TableLayout::SK => 'EMAIL', 'userId' => $userId],
                'attribute_not_exists(#pk)',
                ['#pk' => TableLayout::PK],
            ),
        ];

        $this->store->transactWrite($operations('u1'));

        self::assertNotNull($this->store->getItem([TableLayout::PK => 'USER#u1', TableLayout::SK => 'PROFILE']));
        self::assertNotNull($this->store->getItem([TableLayout::PK => 'EMAIL#ada@example.com', TableLayout::SK => 'EMAIL']));

        // The same email again, lock first: the lock exists (fails), the new user item does not (None).
        [$userPut, $lockPut] = $operations('u2');
        $secondOperations = [$lockPut, $userPut];
        try {
            $this->store->transactWrite($secondOperations);
            self::fail('Expected TransactionCancelled');
        } catch (TransactionCancelled $e) {
            self::assertSame(['ConditionalCheckFailed', 'None'], $e->reasons);
        }
        self::assertNull($this->store->getItem([TableLayout::PK => 'USER#u2', TableLayout::SK => 'PROFILE']));
    }

    public function testConditionFailureInsideATransactionDoesNotLeakItemData(): void
    {
        $this->store->putItem([TableLayout::PK => 'A', TableLayout::SK => 'B', 'note' => 'private-note-xyz']);

        try {
            $this->store->transactWrite([TransactionOperation::put([TableLayout::PK => 'A', TableLayout::SK => 'B', 'note' => 'other'], 'attribute_not_exists(#pk)', ['#pk' => TableLayout::PK])]);
            self::fail('Expected TransactionCancelled');
        } catch (TransactionCancelled $e) {
            self::assertStringNotContainsString('private-note-xyz', $e->getMessage());
        }
    }
}
