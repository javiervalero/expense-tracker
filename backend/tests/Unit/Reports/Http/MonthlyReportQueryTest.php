<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Http;

use App\Expenses\Listing\InvalidQuery;
use App\Reports\Http\MonthlyReportQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The query of `GET /reports/monthly` (D4): only `month`, required, one valid `YYYY-MM`. A refusal never echoes the value.
 */
final class MonthlyReportQueryTest extends TestCase
{
    public function testAValidMonthIsAccepted(): void
    {
        $query = MonthlyReportQuery::fromQuery(['month' => '2026-10']);

        self::assertSame('2026-10', $query->month->value());
    }

    public function testTheEdgesOfTheRangeAreAccepted(): void
    {
        self::assertSame('2000-01', MonthlyReportQuery::fromQuery(['month' => '2000-01'])->month->value());
        self::assertSame('2099-12', MonthlyReportQuery::fromQuery(['month' => '2099-12'])->month->value());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidMonths(): iterable
    {
        yield 'missing' => [[]];
        yield 'array value' => [['month' => ['2026-10']]];
        yield 'null value' => [['month' => null]];
        yield 'integer value' => [['month' => 202610]];
        yield 'empty' => [['month' => '']];
        yield 'one digit month' => [['month' => '2026-1']];
        yield 'month 13' => [['month' => '2026-13']];
        yield 'month 00' => [['month' => '2026-00']];
        yield 'before the range' => [['month' => '1999-12']];
        yield 'after the range' => [['month' => '2100-01']];
        yield 'leading space' => [['month' => ' 2026-10']];
        yield 'trailing space' => [['month' => '2026-10 ']];
        yield 'trailing newline' => [['month' => "2026-10\n"]];
        yield 'full-width digits' => [['month' => '２０２６-１０']];
        yield 'a full date' => [['month' => '2026-10-01']];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('invalidMonths')]
    public function testAMissingOrInvalidMonthIsRefusedWithAFixedDetail(array $query): void
    {
        try {
            MonthlyReportQuery::fromQuery($query);
            self::fail('Expected an InvalidQuery.');
        } catch (InvalidQuery $refused) {
            self::assertSame('The month parameter is not valid.', $refused->getMessage());
            self::assertSame(InvalidQuery::MONTH, $refused->parameter);
        }
    }

    public function testAnUnknownParameterIsRefusedEvenWithAValidMonth(): void
    {
        try {
            MonthlyReportQuery::fromQuery(['month' => '2026-10', 'limit' => '5']);
            self::fail('Expected an InvalidQuery.');
        } catch (InvalidQuery $refused) {
            self::assertSame('The query string has a parameter this operation does not accept.', $refused->getMessage());
        }
    }

    public function testTheDetailNeverEchoesTheValue(): void
    {
        foreach ([['month' => 'SECRET-VALUE-7'], ['SECRET-NAME-7' => '2026-10']] as $query) {
            try {
                MonthlyReportQuery::fromQuery($query);
                self::fail('Expected an InvalidQuery.');
            } catch (InvalidQuery $refused) {
                self::assertStringNotContainsString('SECRET', $refused->getMessage());
            }
        }
    }
}
