<?php

declare(strict_types=1);

namespace App\Reports\Http;

use App\Http\Response;
use App\Reports\CategoryTotal;
use App\Reports\MonthlyReport;
use JsonException;

/**
 * The body of `GET /reports/monthly` (D8), mapped field by field to exactly `{month, totalCents, expenseCount, complete,
 * categories: [{categoryId, name, totalCents, expenseCount}]}`. The Uncategorized row has a null id and a null name.
 */
final class MonthlyReportResponse
{
    private function __construct() {}

    /**
     * @return array{month: string, totalCents: int, expenseCount: int, complete: bool, categories: list<array{categoryId: string|null, name: string|null, totalCents: int, expenseCount: int}>}
     */
    public static function fields(MonthlyReport $report): array
    {
        return [
            'month' => $report->month->value(),
            'totalCents' => $report->totalCents,
            'expenseCount' => $report->expenseCount,
            'complete' => $report->complete,
            'categories' => array_map(static fn(CategoryTotal $row): array => [
                'categoryId' => $row->categoryId,
                'name' => $row->name,
                'totalCents' => $row->totalCents,
                'expenseCount' => $row->expenseCount,
            ], $report->categories),
        ];
    }

    /**
     * @throws JsonException
     */
    public static function ok(MonthlyReport $report): Response
    {
        return Response::json(200, self::fields($report));
    }
}
