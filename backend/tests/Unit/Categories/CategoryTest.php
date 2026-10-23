<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories;

use App\Categories\Category;
use App\Categories\CategoryName;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    private const ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';

    private static function categoryName(string $text): CategoryName
    {
        $name = CategoryName::parse($text);
        self::assertInstanceOf(CategoryName::class, $name);

        return $name;
    }

    public function testANewCategoryStartsAtVersionOneWithTheKeyOfItsName(): void
    {
        $now = new DateTimeImmutable('2026-10-23T10:00:00+00:00');
        $name = self::categoryName('Food');

        $category = Category::new(self::ID, $name, $now);

        self::assertSame(self::ID, $category->id);
        self::assertSame($name, $category->name);
        self::assertSame($name->key(), $category->nameKey);
        self::assertSame(1, $category->version);
        self::assertEquals($now, $category->createdAt);
        self::assertEquals($now, $category->updatedAt);
    }

    public function testRenamedIsANewCategoryWithTheNextVersionAndTheNewKey(): void
    {
        $created = new DateTimeImmutable('2026-10-23T10:00:00+00:00');
        $later = new DateTimeImmutable('2026-10-23T11:30:00+00:00');
        $original = Category::new(self::ID, self::categoryName('Food'), $created);

        $renamed = $original->renamed(self::categoryName('Groceries'), $later);

        self::assertNotSame($original, $renamed);
        self::assertSame('Food', $original->name->value());
        self::assertSame(1, $original->version);
        self::assertSame(self::ID, $renamed->id);
        self::assertSame('Groceries', $renamed->name->value());
        self::assertSame(self::categoryName('Groceries')->key(), $renamed->nameKey);
        self::assertSame(2, $renamed->version);
        self::assertEquals($created, $renamed->createdAt);
        self::assertEquals($later, $renamed->updatedAt);
    }

    public function testACaseOnlyRenameKeepsTheStoredKeyEvenIfItDiffersFromTheRecomputedOne(): void
    {
        $stored = str_repeat('b', 64);
        $now = new DateTimeImmutable('2026-10-23T10:00:00+00:00');
        $original = new Category(self::ID, self::categoryName('Food'), $stored, 4, $now, $now);

        $renamed = $original->renamed(self::categoryName('FOOD'), $now);

        self::assertSame(self::categoryName('FOOD')->key(), $renamed->nameKey, 'a rename always stores the key of the new name');
        self::assertSame($stored, $original->nameKey);
    }

    public function testTimesAreKeptInWholeSecondsInUtcSoTheObjectEqualsWhatIsStored(): void
    {
        $now = new DateTimeImmutable('2026-10-23T12:00:00.987654+02:00');

        $created = Category::new(self::ID, self::categoryName('Food'), $now);
        $renamed = $created->renamed(self::categoryName('Groceries'), new DateTimeImmutable('2026-10-23T13:00:00.5+00:00'));

        self::assertSame('2026-10-23T10:00:00+00:00', $created->createdAt->format('c'));
        self::assertSame('2026-10-23T10:00:00+00:00', $created->updatedAt->format('c'));
        self::assertSame('2026-10-23T10:00:00+00:00', $renamed->createdAt->format('c'));
        self::assertSame('2026-10-23T13:00:00+00:00', $renamed->updatedAt->format('c'));
    }

    public function testAnIdThatIsNotAUuidIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Category::new('not-a-uuid', self::categoryName('Food'), new DateTimeImmutable());
    }

    public function testAVersionBelowOneIsRefused(): void
    {
        $now = new DateTimeImmutable();
        $this->expectException(InvalidArgumentException::class);

        new Category(self::ID, self::categoryName('Food'), str_repeat('a', 64), 0, $now, $now);
    }

    public function testANameKeyThatIsNotSha256HexIsRefused(): void
    {
        $now = new DateTimeImmutable();
        $this->expectException(InvalidArgumentException::class);

        new Category(self::ID, self::categoryName('Food'), 'ABC', 1, $now, $now);
    }
}
