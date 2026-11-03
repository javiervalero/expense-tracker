<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Http;

use App\Reports\CategoryTotal;
use App\Reports\Http\MonthlyReportResponse;
use App\Reports\MonthlyReport;
use App\Reports\ReportMonth;
use PHPUnit\Framework\TestCase;

/**
 * The body of `GET /reports/monthly` (D8), mapped field by field.
 */
final class MonthlyReportResponseTest extends TestCase
{
    private const FOOD = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';

    private static function month(): ReportMonth
    {
        $month = ReportMonth::parse('2026-10');
        self::assertInstanceOf(ReportMonth::class, $month);

        return $month;
    }

    public function testItMapsEveryFieldInTheDocumentedOrder(): void
    {
        $report = new MonthlyReport(self::month(), 5010, 3, false, [
            new CategoryTotal(self::FOOD, 'Food', 4000, 2),
            new CategoryTotal(null, null, 1010, 1),
        ]);

        $response = MonthlyReportResponse::ok($report);

        self::assertSame(200, $response->status);
        self::assertSame(
            '{"month":"2026-10","totalCents":5010,"expenseCount":3,"complete":false,"categories":['
            . '{"categoryId":"' . self::FOOD . '","name":"Food","totalCents":4000,"expenseCount":2},'
            . '{"categoryId":null,"name":null,"totalCents":1010,"expenseCount":1}]}',
            $response->body,
        );
    }

    public function testTheUncategorizedRowHasANullIdAndANullName(): void
    {
        $fields = MonthlyReportResponse::fields(new MonthlyReport(self::month(), 7, 1, true, [new CategoryTotal(null, null, 7, 1)]));

        self::assertSame([['categoryId' => null, 'name' => null, 'totalCents' => 7, 'expenseCount' => 1]], $fields['categories']);
    }

    public function testAnEmptyMonthHasAnEmptyCategoryListNotAnObject(): void
    {
        $response = MonthlyReportResponse::ok(new MonthlyReport(self::month(), 0, 0, true, []));

        self::assertSame('{"month":"2026-10","totalCents":0,"expenseCount":0,"complete":true,"categories":[]}', $response->body);
    }
}
