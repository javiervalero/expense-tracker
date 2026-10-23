<?php

declare(strict_types=1);

namespace App\Tests\Integration\Categories;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryName;
use App\Categories\CategoryRepository;
use App\Categories\CategoryResolver;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * CategoryResolver over the real CategoryRepository on DynamoDB Local, on a table of its own. Ids, names and times
 * are invented.
 */
final class CategoryResolverTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_USER = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const TRAVEL_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const NOW = '2026-10-23T10:00:00+00:00';
    /**
     * More than the soft cap of 100 (B8): proves the resolver has no hard cap. It is NOT a paging test: 120 small
     * items fit in one DynamoDB page. Following `LastEvaluatedKey` is covered in Unit/Categories/CategoryRepositoryTest.
     */
    private const MANY = 120;

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

    private function resolverFor(string $userId = self::USER): CategoryResolver
    {
        return new CategoryResolver($this->categories, self::owner($userId));
    }

    private static function category(string $id, string $text): Category
    {
        $name = CategoryName::parse($text);
        self::assertInstanceOf(CategoryName::class, $name);

        return Category::new($id, $name, new DateTimeImmutable(self::NOW));
    }

    public function testAnotherUsersCategoriesAreInvisible(): void
    {
        $this->categories->create(self::owner(), self::category(self::FOOD_ID, 'Food'));
        $this->categories->create(self::owner(self::OTHER_USER), self::category(self::TRAVEL_ID, 'Travel'));

        $resolver = $this->resolverFor();

        self::assertSame(self::FOOD_ID, $resolver->resolve(self::FOOD_ID));
        self::assertNull($resolver->resolve(self::TRAVEL_ID));
        self::assertFalse($resolver->has(self::TRAVEL_ID));
        self::assertSame(['Food'], array_map(static fn(Category $c): string => $c->name->value(), $resolver->all()));
    }

    public function testTheProfileItemIsNeverACategory(): void
    {
        $this->store->putItem(['PK' => 'USER#' . self::USER, 'SK' => 'PROFILE', 'userId' => self::USER, 'passwordHash' => 'invented-hash']);
        $this->categories->create(self::owner(), self::category(self::FOOD_ID, 'Food'));

        self::assertCount(1, $this->resolverFor()->all());
    }

    public function testMoreCategoriesThanTheSoftCapAllResolveInOrder(): void
    {
        $ids = [];
        for ($index = 0; $index < self::MANY; ++$index) {
            $id = sprintf('%08x-0000-4000-8000-000000000000', $index + 1);
            $ids[] = $id;
            $this->categories->create(self::owner(), self::category($id, sprintf('Category %03d %s', $index, str_repeat('x', 30))));
        }

        $resolver = $this->resolverFor();

        foreach ($ids as $id) {
            self::assertSame($id, $resolver->resolve($id));
        }
        self::assertSame($ids, array_map(static fn(Category $c): string => $c->id, $resolver->all()));
    }

    public function testAfterADeleteANewResolverGivesNull(): void
    {
        $food = self::category(self::FOOD_ID, 'Food');
        $this->categories->create(self::owner(), $food);
        self::assertSame(self::FOOD_ID, $this->resolverFor()->resolve(self::FOOD_ID));

        $this->categories->delete(self::owner(), $food);

        self::assertNull($this->resolverFor()->resolve(self::FOOD_ID));
    }
}
