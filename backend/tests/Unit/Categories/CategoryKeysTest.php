<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories;

use App\Auth\AuthKeys;
use App\Auth\Session\Principal;
use App\Categories\CategoryKeys;
use App\Categories\CategoryName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryKeysTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const CATEGORY = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';

    private static function owner(string $userId = self::USER): Principal
    {
        return new Principal($userId, str_repeat('a', 64));
    }

    public function testCategoryKeyIsInTheUsersPartition(): void
    {
        self::assertSame(
            ['PK' => AuthKeys::userPartition(self::USER), 'SK' => 'CAT#' . self::CATEGORY],
            CategoryKeys::category(self::owner(), self::CATEGORY),
        );
    }

    public function testNameLockKeyUsesTheCaseFoldedNameKey(): void
    {
        $name = CategoryName::parse('Eating out');
        self::assertInstanceOf(CategoryName::class, $name);

        self::assertSame(
            ['PK' => 'USER#' . self::USER, 'SK' => 'CATNAME#' . $name->key()],
            CategoryKeys::nameLockOfStoredKey(self::owner(), $name->key()),
        );
    }

    #[DataProvider('badStoredKeys')]
    public function testNameLockOfAStoredKeyRefusesAnythingButSha256Hex(string $storedKey): void
    {
        $this->expectException(InvalidArgumentException::class);

        CategoryKeys::nameLockOfStoredKey(self::owner(), $storedKey);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badStoredKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'upper case' => [str_repeat('A', 64)];
        yield 'not hex' => [str_repeat('g', 64)];
        yield 'trailing newline' => [str_repeat('a', 64) . "\n"];
        yield 'a sort key prefix inside' => ['CATNAME#' . str_repeat('a', 56)];
    }

    public function testNameLockSortKeyIsThePrefixAnd64LowerCaseHexCharacters(): void
    {
        $name = CategoryName::parse('Eating out');
        self::assertInstanceOf(CategoryName::class, $name);

        self::assertMatchesRegularExpression('/^CATNAME#[0-9a-f]{64}$/', CategoryKeys::nameLockOfStoredKey(self::owner(), $name->key())['SK']);
    }

    public function testNamesThatDifferOnlyInCaseShareTheLockKey(): void
    {
        $a = CategoryName::parse('Straße');
        $b = CategoryName::parse('STRASSE');
        self::assertInstanceOf(CategoryName::class, $a);
        self::assertInstanceOf(CategoryName::class, $b);

        self::assertSame(CategoryKeys::nameLockOfStoredKey(self::owner(), $a->key()), CategoryKeys::nameLockOfStoredKey(self::owner(), $b->key()));
    }

    public function testTheLockPrefixDoesNotStartWithTheCategoryPrefix(): void
    {
        self::assertFalse(str_starts_with(CategoryKeys::NAME_LOCK_PREFIX, CategoryKeys::PREFIX));
        $name = CategoryName::parse('Rent');
        self::assertInstanceOf(CategoryName::class, $name);
        self::assertFalse(str_starts_with(CategoryKeys::nameLockOfStoredKey(self::owner(), $name->key())['SK'], CategoryKeys::PREFIX));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => [strtoupper(self::CATEGORY)];
        yield 'version 1' => ['9d1c7a52-0b3e-1f68-8a21-7e5d4c3b2a10'];
        yield 'separator' => ['a#b'];
        yield 'trailing newline' => [self::CATEGORY . "\n"];
    }

    #[DataProvider('badIds')]
    public function testCategoryKeyRefusesABadCategoryId(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        CategoryKeys::category(self::owner(), $id);
    }

    #[DataProvider('badIds')]
    public function testKeysRefuseABadUserId(string $id): void
    {
        $name = CategoryName::parse('Rent');
        self::assertInstanceOf(CategoryName::class, $name);

        $this->expectException(InvalidArgumentException::class);

        CategoryKeys::nameLockOfStoredKey(self::owner($id), $name->key());
    }

    #[DataProvider('badIds')]
    public function testCategoryKeyRefusesABadUserId(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        CategoryKeys::category(self::owner($id), self::CATEGORY);
    }
}
