<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses;

use App\Expenses\Amount;
use App\Expenses\Description;
use App\Expenses\Expense;
use App\Expenses\ExpenseDate;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExpenseTest extends TestCase
{
    private const ID = '5c8e1f3a-2b4d-4a6c-8e0f-1a2b3c4d5e6f';
    private const CATEGORY_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';

    private static function date(string $text): ExpenseDate
    {
        $date = ExpenseDate::parse($text);
        self::assertInstanceOf(ExpenseDate::class, $date);

        return $date;
    }

    private static function amount(int $cents): Amount
    {
        $amount = Amount::parse($cents);
        self::assertInstanceOf(Amount::class, $amount);

        return $amount;
    }

    private static function description(string $text): Description
    {
        $description = Description::parse($text);
        self::assertInstanceOf(Description::class, $description);

        return $description;
    }

    private static function lunch(?string $categoryId = null): Expense
    {
        return Expense::new(self::ID, self::date('2026-10-20'), self::amount(1250), self::description('Lunch'), $categoryId, new DateTimeImmutable('2026-10-23T10:00:00.987654+02:00'));
    }

    public function testANewExpenseStartsAtVersionOneWithWholeSecondTimesInUtc(): void
    {
        $expense = self::lunch(self::CATEGORY_ID);

        self::assertSame(self::ID, $expense->id);
        self::assertSame('2026-10-20', $expense->date->value());
        self::assertSame(1250, $expense->amount->cents());
        self::assertSame('Lunch', $expense->description->value());
        self::assertSame(self::CATEGORY_ID, $expense->categoryId);
        self::assertSame(1, $expense->version);
        self::assertSame('2026-10-23T08:00:00+00:00', $expense->createdAt->format('c'));
        self::assertSame('000000', $expense->updatedAt->format('u'));
        self::assertEquals($expense->createdAt, $expense->updatedAt);
    }

    public function testUpdatedIsANewExpenseWithTheNextVersionTheSameIdAndTheOriginalCreationTime(): void
    {
        $original = self::lunch(self::CATEGORY_ID);

        $changed = $original->updated(self::date('2026-10-21'), self::amount(900), self::description('Coffee'), null, new DateTimeImmutable('2026-10-24T09:30:15.5+00:00'));

        self::assertSame(self::ID, $changed->id);
        self::assertSame(2, $changed->version);
        self::assertSame('2026-10-21', $changed->date->value());
        self::assertSame(900, $changed->amount->cents());
        self::assertNull($changed->categoryId);
        self::assertEquals($original->createdAt, $changed->createdAt);
        self::assertSame('2026-10-24T09:30:15+00:00', $changed->updatedAt->format('c'));
        self::assertSame(1, $original->version, 'the original is not changed');
        self::assertSame(self::CATEGORY_ID, $original->categoryId);
    }

    public function testAnIdThatIsNotALowerCaseUuidIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Expense::new('NOT-A-UUID', self::date('2026-10-20'), self::amount(1), self::description('x'), null, new DateTimeImmutable());
    }

    public function testACategoryIdThatIsNotALowerCaseUuidIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::lunch(strtoupper(self::CATEGORY_ID));
    }

    public function testAVersionBelowOneIsRefused(): void
    {
        $now = new DateTimeImmutable('2026-10-23T10:00:00+00:00');
        $this->expectException(InvalidArgumentException::class);

        new Expense(self::ID, self::date('2026-10-20'), self::amount(1), self::description('x'), null, 0, $now, $now);
    }
}
