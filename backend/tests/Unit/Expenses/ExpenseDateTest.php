<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses;

use App\Expenses\ExpenseDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpenseDateTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function valid(): array
    {
        return [
            'leap day' => ['2024-02-29'],
            'ordinary' => ['2026-01-05'],
            'minimum' => ['2000-01-01'],
            'maximum' => ['2099-12-31'],
            'future' => ['2060-06-15'],
        ];
    }

    #[DataProvider('valid')]
    public function testItAcceptsValidDates(string $input): void
    {
        $date = ExpenseDate::parse($input);

        self::assertInstanceOf(ExpenseDate::class, $date);
        self::assertSame($input, $date->value());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        return [
            'not a leap year' => ['2023-02-29'],
            'month 13' => ['2026-13-01'],
            'day 32' => ['2026-01-32'],
            'day 0' => ['2026-01-00'],
            'unpadded' => ['2026-1-05'],
            'with time' => ['2026-01-05T00:00'],
            'leading space' => [' 2026-01-05'],
            'trailing newline' => ["2026-01-05\n"],
            'before the minimum' => ['1999-12-31'],
            'after the maximum' => ['2100-01-01'],
            'empty' => [''],
            'slashes' => ['2026/01/05'],
            'non-ASCII digits' => ["\u{0662}\u{0660}\u{0662}\u{0666}-01-05"],
            'text' => ['yesterday'],
        ];
    }

    #[DataProvider('invalid')]
    public function testItRefusesInvalidDates(string $input): void
    {
        self::assertNull(ExpenseDate::parse($input));
    }

    public function testTheBoundsHelpers(): void
    {
        self::assertSame('2000-01-01', ExpenseDate::min()->value());
        self::assertSame('2099-12-31', ExpenseDate::max()->value());
    }

    public function testIsAfterComparesCalendarDays(): void
    {
        $early = ExpenseDate::parse('2026-01-05');
        $late = ExpenseDate::parse('2026-01-06');
        self::assertNotNull($early);
        self::assertNotNull($late);

        self::assertTrue($late->isAfter($early));
        self::assertFalse($early->isAfter($late));
        self::assertFalse($early->isAfter($early));
    }
}
