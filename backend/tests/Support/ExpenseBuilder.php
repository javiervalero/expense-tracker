<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Expenses\Amount;
use App\Expenses\Description;
use App\Expenses\Expense;
use App\Expenses\ExpenseDate;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/** Invented expenses with deterministic v4 ids, for the listing tests. */
final class ExpenseBuilder
{
    /** The same shape of id for every number: lower-case, version 4, variant 8. */
    public static function uuid(int $n): string
    {
        return sprintf('%08x-1111-4111-8111-%012x', $n, $n);
    }

    public static function expense(int $n, string $date = '2026-10-20', string $text = 'Lunch', ?string $categoryId = null, ?string $id = null, ?int $cents = null, string $createdAt = '2026-10-23T10:00:00+00:00'): Expense
    {
        $day = ExpenseDate::parse($date);
        $amount = Amount::parse($cents ?? 1000 + $n);
        $description = Description::parse($text);
        Assert::assertInstanceOf(ExpenseDate::class, $day);
        Assert::assertInstanceOf(Amount::class, $amount);
        Assert::assertInstanceOf(Description::class, $description);

        return Expense::new($id ?? self::uuid($n), $day, $amount, $description, $categoryId, new DateTimeImmutable($createdAt));
    }
}
