<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * The spending of one month (D8). `complete` is false when the month had more expenses than the builder's cap: the
 * totals then cover the newest ones only. `categories` holds only categories with at least one expense, biggest total
 * first.
 */
final readonly class MonthlyReport
{
    /** @param list<CategoryTotal> $categories */
    public function __construct(
        public ReportMonth $month,
        public int $totalCents,
        public int $expenseCount,
        public bool $complete,
        public array $categories,
    ) {}
}
