<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryLister;
use App\Categories\CategoryName;
use App\Categories\CategoryResolver;
use App\Tests\Support\CountingCategoryLister;
use App\Infrastructure\DynamoDb\StorageFailure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CategoryResolver over a fake lister that counts its calls. Ids, names and times are invented.
 */
final class CategoryResolverTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const TRAVEL_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const OTHER_USERS_ID = '5a4b3c2d-1e0f-4a9b-8c7d-6e5f4a3b2c1d';
    private const NOW = '2026-10-23T10:00:00+00:00';

    private static function owner(): Principal
    {
        return new Principal(self::USER, str_repeat('a', 64));
    }

    private static function category(string $id, string $text): Category
    {
        $name = CategoryName::parse($text);
        self::assertInstanceOf(CategoryName::class, $name);

        return Category::new($id, $name, new DateTimeImmutable(self::NOW));
    }

    /** @param list<Category> $stored */
    private static function lister(array $stored): CountingCategoryLister
    {
        return new CountingCategoryLister($stored);
    }

    public function testAKnownIdResolvesToItself(): void
    {
        $resolver = new CategoryResolver(self::lister([self::category(self::FOOD_ID, 'Food')]), self::owner());

        self::assertSame(self::FOOD_ID, $resolver->resolve(self::FOOD_ID));
        self::assertTrue($resolver->has(self::FOOD_ID));
    }

    public function testAnUnknownDeletedOrOtherUsersIdGivesNull(): void
    {
        $resolver = new CategoryResolver(self::lister([self::category(self::FOOD_ID, 'Food')]), self::owner());

        self::assertNull($resolver->resolve(self::OTHER_USERS_ID));
        self::assertFalse($resolver->has(self::OTHER_USERS_ID));
    }

    public function testANullIdGivesNull(): void
    {
        $lister = self::lister([self::category(self::FOOD_ID, 'Food')]);
        $resolver = new CategoryResolver($lister, self::owner());

        self::assertNull($resolver->resolve(null));
        self::assertSame(0, $lister->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function notLowerCaseUuids(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['food'];
        yield 'upper case' => [strtoupper(self::FOOD_ID)];
        yield 'braces' => ['{' . self::FOOD_ID . '}'];
        yield 'padded' => [' ' . self::FOOD_ID];
        yield 'sort key' => ['CAT#' . self::FOOD_ID];
        yield 'nil uuid' => ['00000000-0000-0000-0000-000000000000'];
    }

    #[DataProvider('notLowerCaseUuids')]
    public function testAnIdThatIsNotALowerCaseUuidGivesNullWithoutReadingStorage(string $id): void
    {
        $lister = self::lister([self::category(self::FOOD_ID, 'Food')]);
        $resolver = new CategoryResolver($lister, self::owner());

        self::assertNull($resolver->resolve($id));
        self::assertFalse($resolver->has($id));
        self::assertSame(0, $lister->calls);
    }

    public function testNothingIsReadUntilTheFirstUse(): void
    {
        $lister = self::lister([]);
        $resolver = new CategoryResolver($lister, self::owner());

        self::assertSame(0, $lister->calls);
        self::assertSame([], $resolver->all());
    }

    public function testTheListerIsCalledOnceForTheOwnerAcrossManyCalls(): void
    {
        $lister = self::lister([self::category(self::FOOD_ID, 'Food'), self::category(self::TRAVEL_ID, 'Travel')]);
        $resolver = new CategoryResolver($lister, self::owner());

        $resolver->resolve(self::FOOD_ID);
        $resolver->resolve(self::TRAVEL_ID);
        $resolver->resolve(self::OTHER_USERS_ID);
        $resolver->has(self::FOOD_ID);
        $resolver->all();
        $resolver->all();

        self::assertSame(1, $lister->calls);
        self::assertSame([self::USER], $lister->owners);
    }

    public function testAFailedReadIsRememberedAndRethrownWithoutQueryingAgain(): void
    {
        $lister = self::lister([self::category(self::FOOD_ID, 'Food')]);
        $lister->failures = 1;
        $resolver = new CategoryResolver($lister, self::owner());

        $first = null;
        try {
            $resolver->resolve(self::FOOD_ID);
        } catch (StorageFailure $failure) {
            $first = $failure;
        }
        self::assertInstanceOf(StorageFailure::class, $first);

        foreach ([
            static fn() => $resolver->resolve(self::FOOD_ID),
            static fn() => $resolver->has(self::FOOD_ID),
            static fn() => $resolver->all(),
        ] as $call) {
            try {
                $call();
                self::fail('A later call should rethrow the first failure.');
            } catch (StorageFailure $again) {
                self::assertSame($first, $again);
            }
        }
        self::assertSame(1, $lister->calls);
    }

    public function testAllIsSortedByFoldedNameThenId(): void
    {
        $lowerCaseFirst = '0a000000-0000-4000-8000-000000000001';
        $lowerCaseSecond = 'ff000000-0000-4000-8000-000000000002';
        $resolver = new CategoryResolver(self::lister([
            self::category(self::TRAVEL_ID, 'travel'),
            self::category($lowerCaseSecond, 'Bills'),
            self::category(self::FOOD_ID, 'Zoo'),
            self::category($lowerCaseFirst, 'BILLS '),
        ]), self::owner());

        // 'Bills' and 'BILLS' fold to the same text; the two cannot both exist in storage (name lock), so the tie break
        // by id is only a guarantee of a stable order.
        self::assertSame(
            [$lowerCaseFirst, $lowerCaseSecond, self::TRAVEL_ID, self::FOOD_ID],
            array_map(static fn(Category $category): string => $category->id, $resolver->all()),
        );
    }

    public function testAllSortsByFoldedNotRawText(): void
    {
        $resolver = new CategoryResolver(self::lister([
            self::category(self::FOOD_ID, 'banana'),
            self::category(self::TRAVEL_ID, 'Apple'),
            self::category(self::OTHER_USERS_ID, 'Cherry'),
        ]), self::owner());

        self::assertSame(
            ['Apple', 'banana', 'Cherry'],
            array_map(static fn(Category $category): string => $category->name->value(), $resolver->all()),
        );
    }

    public function testAllComparesNumericLookingNamesAsTextNotAsNumbers(): void
    {
        $resolver = new CategoryResolver(self::lister([
            self::category(self::FOOD_ID, '9'),
            self::category(self::TRAVEL_ID, '10'),
            self::category(self::OTHER_USERS_ID, '1e1'),
        ]), self::owner());

        self::assertSame(
            ['10', '1e1', '9'],
            array_map(static fn(Category $category): string => $category->name->value(), $resolver->all()),
        );
    }

    public function testAllOfNoCategoriesIsEmpty(): void
    {
        self::assertSame([], (new CategoryResolver(self::lister([]), self::owner()))->all());
    }
}
