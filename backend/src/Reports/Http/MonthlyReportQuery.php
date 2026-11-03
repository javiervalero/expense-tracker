<?php

declare(strict_types=1);

namespace App\Reports\Http;

use App\Expenses\Listing\InvalidQuery;
use App\Reports\ReportMonth;

/**
 * Everything a `GET /reports/monthly` request asks for, validated (D4): `month` is the only parameter, it is required and
 * it is one text that parses as a {@see ReportMonth}. Every refusal is an {@see InvalidQuery} whose detail never carries
 * the value, and nothing is read before the query is accepted.
 */
final readonly class MonthlyReportQuery
{
    private function __construct(public ReportMonth $month) {}

    /**
     * @param array<array-key, mixed> $query the parsed query string (a non-text value, such as `month[]=`, is refused)
     *
     * @throws InvalidQuery
     */
    public static function fromQuery(array $query): self
    {
        foreach (array_keys($query) as $name) {
            if ($name !== InvalidQuery::MONTH) {
                throw InvalidQuery::unknownParameter();
            }
        }
        $value = $query[InvalidQuery::MONTH] ?? null;
        $month = is_string($value) ? ReportMonth::parse($value) : null;

        return new self($month ?? throw InvalidQuery::invalid(InvalidQuery::MONTH));
    }
}
