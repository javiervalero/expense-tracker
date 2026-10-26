<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Input;

use App\Http\Input\StringMember;
use App\Http\Problem\FieldError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StringMemberTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStrings(): iterable
    {
        yield 'null' => [null];
        yield 'int' => [12];
        yield 'float' => [1.5];
        yield 'bool' => [true];
        yield 'array' => [['a']];
    }

    public function testRequiredReturnsTheString(): void
    {
        self::assertSame('2026-01-02', StringMember::required(['date' => '2026-01-02'], 'date'));
    }

    public function testRequiredReturnsAnEmptyString(): void
    {
        self::assertSame('', StringMember::required(['date' => ''], 'date'));
    }

    public function testRequiredReportsAnAbsentMember(): void
    {
        $error = StringMember::required(['other' => 'x'], 'date');

        self::assertInstanceOf(FieldError::class, $error);
        self::assertSame('/date', $error->pointer);
        self::assertSame('This field is required.', $error->detail);
    }

    #[DataProvider('nonStrings')]
    public function testRequiredRefusesANonString(mixed $value): void
    {
        $error = StringMember::required(['name' => $value], 'name');

        self::assertInstanceOf(FieldError::class, $error);
        self::assertSame('/name', $error->pointer);
        self::assertSame('Must be a string.', $error->detail);
    }

    public function testOptionalReturnsTheString(): void
    {
        self::assertSame('lunch', StringMember::optional(['description' => 'lunch'], 'description'));
    }

    public function testOptionalReturnsAnEmptyString(): void
    {
        self::assertSame('', StringMember::optional(['description' => ''], 'description'));
    }

    public function testOptionalReturnsNullForAnAbsentMember(): void
    {
        self::assertNull(StringMember::optional([], 'description'));
    }

    #[DataProvider('nonStrings')]
    public function testOptionalRefusesAPresentNonStringIncludingNull(mixed $value): void
    {
        $error = StringMember::optional(['description' => $value], 'description');

        self::assertInstanceOf(FieldError::class, $error);
        self::assertSame('/description', $error->pointer);
        self::assertSame('Must be a string.', $error->detail);
    }
}
