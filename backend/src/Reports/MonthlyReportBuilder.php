<?php

declare(strict_types=1);

namespace App\Reports;

use App\Auth\Session\Principal;
use App\Categories\CategoryResolver;
use App\Expenses\ExpenseDate;
use App\Expenses\InvalidStartKey;
use App\Expenses\Listing\ExpenseRangeReader;
use App\Infrastructure\DynamoDb\StorageFailure;
use InvalidArgumentException;
use LogicException;

/**
 * Adds up one month of the principal's expenses per category (D5, D7, D8). It reads the month newest first in batches
 * of {@see self::BATCH} and keeps only running totals, so it never holds the month. Reading stops at the cap
 * ({@see self::MAX_EXPENSES} unless a test lowers it); when the cap is reached with a continuation key, one probe of
 * one item tells "exactly the cap" (complete) from "more" (the totals then cover the newest ones only).
 *
 * The resolver must be the principal's: {@see CategoryResolver::assertFor()} runs before any read. An expense whose
 * category does not resolve (none, deleted, never the owner's) counts as Uncategorized. A StorageFailure from the
 * reader or the resolver, and an InvalidStartKey on a key taken from our own batch, are server errors and are not caught.
 */
final readonly class MonthlyReportBuilder
{
    public const int BATCH = 1000;
    public const int MAX_EXPENSES = 10_000;

    /** @param int<1, max> $maxExpenses */
    public function __construct(
        private ExpenseRangeReader $reader,
        private int $maxExpenses = self::MAX_EXPENSES,
    ) {}

    /**
     * @throws LogicException when the resolver was built for another principal
     * @throws InvalidArgumentException when the principal's user id is not a lower-case UUID
     * @throws InvalidStartKey
     * @throws StorageFailure
     */
    public function build(Principal $owner, ReportMonth $month, CategoryResolver $categories): MonthlyReport
    {
        $categories->assertFor($owner);
        $from = $month->firstDay();
        $to = $month->lastDay();
        // Running totals by category id; the empty string stands for Uncategorized.
        $sums = [];
        $examined = 0;
        $startKey = null;
        do {
            $limit = min(self::BATCH, $this->maxExpenses - $examined);
            $batch = $this->reader->pageByDate($owner, $from, $to, $startKey, $limit)->checkedAgainst($limit);
            foreach ($batch->items as $expense) {
                $key = $categories->resolve($expense->categoryId) ?? '';
                $sums[$key] = [($sums[$key][0] ?? 0) + $expense->amount->cents(), ($sums[$key][1] ?? 0) + 1];
            }
            $examined += count($batch->items);
            $startKey = $batch->lastKey;
        } while ($startKey !== null && $examined < $this->maxExpenses);

        $complete = $startKey === null || $this->nothingAfter($owner, $from, $to, $startKey);

        return $this->report($month, $sums, $categories, $examined, $complete);
    }

    /**
     * @param array<string, string> $startKey
     */
    private function nothingAfter(Principal $owner, ExpenseDate $from, ExpenseDate $to, array $startKey): bool
    {
        return $this->reader->pageByDate($owner, $from, $to, $startKey, 1)->checkedAgainst(1)->items === [];
    }

    /**
     * @param array<string, array{int, int}> $sums
     */
    private function report(ReportMonth $month, array $sums, CategoryResolver $categories, int $examined, bool $complete): MonthlyReport
    {
        $position = [];
        $names = [];
        if ($sums !== []) {
            foreach ($categories->all() as $index => $category) {
                $position[$category->id] = $index;
                $names[$category->id] = $category->name->value();
            }
        }
        $rows = [];
        $total = 0;
        foreach ($sums as $key => [$cents, $count]) {
            $total += $cents;
            $rows[] = ['order' => $position[$key] ?? PHP_INT_MAX, 'row' => new CategoryTotal($key === '' ? null : $key, $names[$key] ?? null, $cents, $count)];
        }
        usort($rows, static fn(array $a, array $b): int => [$b['row']->totalCents, $a['order']] <=> [$a['row']->totalCents, $b['order']]);

        return new MonthlyReport($month, $total, $examined, $complete, array_map(static fn(array $entry): CategoryTotal => $entry['row'], $rows));
    }
}
