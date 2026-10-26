<?php

declare(strict_types=1);

namespace App\Tests\Integration\Categories;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryKeys;
use App\Categories\CategoryName;
use App\Categories\CategoryNameTaken;
use App\Categories\CategoryRepository;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Middleware;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The deterministic race proof of the category name lock (B6): the interleaving is forced, not hoped for. An SDK
 * middleware on creator A's client runs creator B's whole operation just before A's first write is sent, so A has
 * already decided on a name that B takes in the meantime. With a lock item and conditional puts exactly one of them
 * wins; a read-then-write check would let both through. (The real parallel proof is CategoryNameLockConcurrencyTest.)
 */
final class CategoryNameLockRaceTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const A_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const B_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const WRITES = ['TransactWriteItems', 'PutItem', 'UpdateItem'];

    private TemporaryTable $table;

    protected function setUp(): void
    {
        $this->table = TemporaryTable::create(LocalAws::fromGlobals()->dynamoDb());
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    private static function owner(): Principal
    {
        return new Principal(self::USER, str_repeat('a', 64));
    }

    private static function category(string $id, string $text): Category
    {
        $name = CategoryName::parse($text);
        self::assertInstanceOf(CategoryName::class, $name);

        return Category::new($id, $name, new DateTimeImmutable('2026-10-23T10:00:00+00:00'));
    }

    private function repositoryOn(DynamoDbClient $client): CategoryRepository
    {
        return new CategoryRepository(new ItemStore($client, $this->table->name));
    }

    /**
     * A's client: right before its first write is sent, `$inside` runs once.
     */
    private function clientRunningBeforeTheFirstWrite(callable $inside): DynamoDbClient
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $fired = false;
        $client->getHandlerList()->appendInit(Middleware::tap(static function (CommandInterface $command) use (&$fired, $inside): void {
            if (!$fired && in_array($command->getName(), self::WRITES, true)) {
                $fired = true;
                $inside();
            }
        }), 'other-creator-inside');

        return $client;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemsWithSortKeyPrefix(string $prefix): array
    {
        return (new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $this->table->name))->query(
            '#pk = :pk AND begins_with(SK, :prefix)',
            ['#pk' => 'PK'],
            [':pk' => 'USER#' . self::USER, ':prefix' => $prefix],
        )->items;
    }

    public function testACreateThatLosesTheNameToACreateInsideItsOwnTransactionIsRefused(): void
    {
        $creatorB = $this->repositoryOn(LocalAws::fromGlobals()->dynamoDb());
        $createdByB = false;
        $creatorA = $this->repositoryOn($this->clientRunningBeforeTheFirstWrite(function () use ($creatorB, &$createdByB): void {
            $creatorB->create(self::owner(), self::category(self::B_ID, 'FOOD'));
            $createdByB = true;
        }));

        $outcome = 'created';
        try {
            $creatorA->create(self::owner(), self::category(self::A_ID, 'Food'));
        } catch (CategoryNameTaken) {
            $outcome = 'taken';
        }

        self::assertTrue($createdByB, 'creator B ran inside creator A');
        self::assertSame('taken', $outcome, 'exactly one of the two creators wins');
        $categories = $this->itemsWithSortKeyPrefix(CategoryKeys::PREFIX);
        self::assertCount(1, $categories);
        self::assertSame(self::B_ID, $categories[0]['categoryId']);
        $locks = $this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX);
        self::assertCount(1, $locks);
        self::assertSame(self::B_ID, $locks[0]['categoryId']);
    }

    public function testARenameThatLosesTheNameToACreateInsideItsOwnTransactionIsRefusedAndChangesNothing(): void
    {
        $setup = $this->repositoryOn(LocalAws::fromGlobals()->dynamoDb());
        $food = self::category(self::A_ID, 'Food');
        $setup->create(self::owner(), $food);
        $createdByB = false;
        $renamer = $this->repositoryOn($this->clientRunningBeforeTheFirstWrite(function () use ($setup, &$createdByB): void {
            $setup->create(self::owner(), self::category(self::B_ID, 'Groceries'));
            $createdByB = true;
        }));
        $groceries = CategoryName::parse('groceries');
        self::assertInstanceOf(CategoryName::class, $groceries);

        $outcome = 'renamed';
        try {
            $renamer->rename(self::owner(), $food, $groceries, 1, new DateTimeImmutable('2026-10-23T12:00:00+00:00'));
        } catch (CategoryNameTaken) {
            $outcome = 'taken';
        }

        self::assertTrue($createdByB, 'the create ran inside the rename');
        self::assertSame('taken', $outcome);
        $stored = $setup->find(self::owner(), self::A_ID);
        self::assertNotNull($stored);
        self::assertSame('Food', $stored->name->value());
        self::assertSame(1, $stored->version);
        self::assertCount(2, $this->itemsWithSortKeyPrefix(CategoryKeys::NAME_LOCK_PREFIX));
    }
}
