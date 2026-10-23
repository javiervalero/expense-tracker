<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses;

use App\Auth\Session\Principal;
use App\Expenses\ExpenseDate;
use App\Expenses\ExpenseKeys;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpenseKeysTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const EXPENSE = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';

    private static function date(string $text): ExpenseDate
    {
        $date = ExpenseDate::parse($text);
        self::assertInstanceOf(ExpenseDate::class, $date);

        return $date;
    }

    private static function owner(string $userId = self::USER): Principal
    {
        return new Principal($userId, str_repeat('a', 64));
    }

    public function testExpenseKeyIsInTheUsersPartition(): void
    {
        self::assertSame(
            ['PK' => 'USER#' . self::USER, 'SK' => 'EXP#' . self::EXPENSE],
            ExpenseKeys::expense(self::owner(), self::EXPENSE),
        );
    }

    public function testReceiptKeyIsReservedForPhaseFive(): void
    {
        self::assertSame(
            ['PK' => 'USER#' . self::USER, 'SK' => 'RCPT#' . self::EXPENSE],
            ExpenseKeys::receipt(self::owner(), self::EXPENSE),
        );
    }

    public function testReceiptKeyDoesNotStartWithTheExpensePrefix(): void
    {
        self::assertFalse(str_starts_with(ExpenseKeys::receipt(self::owner(), self::EXPENSE)['SK'], ExpenseKeys::PREFIX));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function extremeDates(): iterable
    {
        yield 'first supported day' => ['2000-01-01'];
        yield 'last supported day' => ['2099-12-31'];
    }

    #[DataProvider('extremeDates')]
    public function testIndexKeyOfAnExtremeDateSortsBetweenTheRangeBoundsOfThatDay(string $text): void
    {
        $day = self::date($text);
        $key = ExpenseKeys::indexKey($day, self::EXPENSE);

        self::assertLessThan(0, strcmp(ExpenseKeys::rangeStart($day), $key));
        self::assertLessThan(0, strcmp($key, ExpenseKeys::rangeEnd($day)));
    }

    public function testIndexKeyIsPrefixDateAndId(): void
    {
        self::assertSame('EXP#2026-03-09#' . self::EXPENSE, ExpenseKeys::indexKey(self::date('2026-03-09'), self::EXPENSE));
    }

    public function testRangeBoundsAreThePrefixWithTheDayAndATilde(): void
    {
        self::assertSame('EXP#2026-03-01', ExpenseKeys::rangeStart(self::date('2026-03-01')));
        self::assertSame('EXP#2026-03-31~', ExpenseKeys::rangeEnd(self::date('2026-03-31')));
    }

    public function testRangeEndSortsAfterEveryIndexKeyOfThatDateAndBeforeTheNextDay(): void
    {
        $day = self::date('2026-03-31');
        $ids = ['00000000-0000-4000-8000-000000000000', 'ffffffff-ffff-4fff-bfff-ffffffffffff', self::EXPENSE];

        foreach ($ids as $id) {
            self::assertGreaterThan(ExpenseKeys::indexKey($day, $id), ExpenseKeys::rangeEnd($day));
            self::assertLessThanOrEqual(ExpenseKeys::indexKey($day, $id), ExpenseKeys::rangeStart($day));
            self::assertLessThan(ExpenseKeys::indexKey(self::date('2026-04-01'), $id), ExpenseKeys::rangeEnd($day));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => [strtoupper(self::EXPENSE)];
        yield 'version 1' => ['9d1c7a52-0b3e-1f68-8a21-7e5d4c3b2a10'];
        yield 'separator' => ['a#b'];
        yield 'trailing newline' => [self::EXPENSE . "\n"];
    }

    #[DataProvider('badIds')]
    public function testEveryBuilderRefusesABadExpenseId(string $id): void
    {
        $builders = [
            static fn(): mixed => ExpenseKeys::expense(self::owner(), $id),
            static fn(): mixed => ExpenseKeys::receipt(self::owner(), $id),
            static fn(): mixed => ExpenseKeys::indexKey(self::date('2026-03-09'), $id),
        ];
        foreach ($builders as $build) {
            try {
                $build();
                self::fail('A bad id must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('badIds')]
    public function testBuildersRefuseABadUserId(string $id): void
    {
        foreach ([ExpenseKeys::expense(...), ExpenseKeys::receipt(...)] as $build) {
            try {
                $build(self::owner($id), self::EXPENSE);
                self::fail('A bad user id must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
