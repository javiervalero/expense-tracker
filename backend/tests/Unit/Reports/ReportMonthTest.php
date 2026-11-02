<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports;

use App\Reports\ReportMonth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The `month` value of the monthly report (D1, D3): `YYYY-MM`, ASCII digits, 2000-01 to 2099-12. */
final class ReportMonthTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validMonths(): iterable
    {
        yield 'first allowed' => ['2000-01'];
        yield 'last allowed' => ['2099-12'];
        yield 'an ordinary month' => ['2026-10'];
        yield 'february' => ['2024-02'];
    }

    #[DataProvider('validMonths')]
    public function testAValidMonthIsAcceptedAndKeepsItsText(string $input): void
    {
        $month = ReportMonth::parse($input);

        self::assertInstanceOf(ReportMonth::class, $month);
        self::assertSame($input, $month->value());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMonths(): iterable
    {
        yield 'empty' => [''];
        yield 'one digit month' => ['2026-1'];
        yield 'month 13' => ['2026-13'];
        yield 'month 00' => ['2026-00'];
        yield 'before the range' => ['1999-12'];
        yield 'after the range' => ['2100-01'];
        yield 'leading space' => [' 2026-10'];
        yield 'trailing space' => ['2026-10 '];
        yield 'trailing newline' => ["2026-10\n"];
        yield 'a full date' => ['2026-10-01'];
        yield 'full-width digits' => ['２０２６-１０'];
        yield 'slash separator' => ['2026/10'];
        yield 'no separator' => ['202610'];
        yield 'letters' => ['abcd-ef'];
        yield 'nul byte' => ["2026-10\0"];
    }

    #[DataProvider('invalidMonths')]
    public function testAnythingElseIsRefused(string $input): void
    {
        self::assertNull(ReportMonth::parse($input));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function days(): iterable
    {
        yield 'leap february' => ['2024-02', '2024-02-01', '2024-02-29'];
        yield 'common february' => ['2023-02', '2023-02-01', '2023-02-28'];
        yield 'a leap year at the start of the range' => ['2000-02', '2000-02-01', '2000-02-29'];
        yield 'a non-leap century year inside the range' => ['2099-02', '2099-02-01', '2099-02-28'];
        yield 'december' => ['2001-12', '2001-12-01', '2001-12-31'];
        yield 'last month of the range' => ['2099-12', '2099-12-01', '2099-12-31'];
        yield 'first month of the range' => ['2000-01', '2000-01-01', '2000-01-31'];
        yield 'a 30 day month' => ['2026-04', '2026-04-01', '2026-04-30'];
    }

    #[DataProvider('days')]
    public function testFirstAndLastDay(string $input, string $first, string $last): void
    {
        $month = ReportMonth::parse($input);

        self::assertInstanceOf(ReportMonth::class, $month);
        self::assertSame($first, $month->firstDay()->value());
        self::assertSame($last, $month->lastDay()->value());
    }
}
