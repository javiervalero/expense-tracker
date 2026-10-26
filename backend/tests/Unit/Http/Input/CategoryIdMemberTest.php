<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Input;

use App\Http\Input\CategoryIdMember;
use App\Http\Problem\FieldError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryIdMemberTest extends TestCase
{
    public function testReturnsTheString(): void
    {
        self::assertSame('abc', CategoryIdMember::optional(['categoryId' => 'abc']));
    }

    public function testReturnsAnEmptyStringUnjudged(): void
    {
        self::assertSame('', CategoryIdMember::optional(['categoryId' => '']));
    }

    public function testReturnsNullForAnAbsentMember(): void
    {
        self::assertNull(CategoryIdMember::optional([]));
    }

    public function testReturnsNullForAJsonNull(): void
    {
        self::assertNull(CategoryIdMember::optional(['categoryId' => null]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function neitherStringNorNull(): iterable
    {
        yield 'int' => [12];
        yield 'float' => [1.5];
        yield 'bool' => [false];
        yield 'array' => [['a']];
    }

    #[DataProvider('neitherStringNorNull')]
    public function testRefusesAnythingElse(mixed $value): void
    {
        $error = CategoryIdMember::optional(['categoryId' => $value]);

        self::assertInstanceOf(FieldError::class, $error);
        self::assertSame('/categoryId', $error->pointer);
        self::assertSame('Must be a category id or null.', $error->detail);
    }
}
