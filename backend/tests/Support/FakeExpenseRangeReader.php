<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Session\Principal;
use App\Expenses\Expense;
use App\Expenses\ExpenseBatch;
use App\Expenses\ExpenseDate;
use App\Expenses\ExpenseKeys;
use App\Expenses\InvalidStartKey;
use App\Expenses\Listing\ExpenseRangeReader;

/**
 * A reader over a fixed list that behaves like the repository on DynamoDB: newest first, resumes after the start key,
 * and (like DynamoDB with a Limit) hands back a last key whenever the batch is full, even when nothing is left.
 * It records every call.
 */
final class FakeExpenseRangeReader implements ExpenseRangeReader
{
    /** @var list<array{startKey: array<string, string>|null, batchLimit: int}> */
    public array $calls = [];

    public bool $refuseStartKey = false;

    /** Refuse the start key from this call on (1 is the first call); null never refuses. */
    public ?int $refuseFromCall = null;

    /** Hand back this many more items than were asked for. */
    public int $overDeliverBy = 0;

    public bool $answerEmptyBatchWithKey = false;

    /** @param list<Expense> $newestFirst */
    public function __construct(private readonly array $newestFirst) {}

    public function pageByDate(Principal $owner, ExpenseDate $from, ExpenseDate $to, ?array $startKey, int $batchLimit): ExpenseBatch
    {
        $this->calls[] = ['startKey' => $startKey, 'batchLimit' => $batchLimit];
        if ($this->refuseStartKey || ($this->refuseFromCall !== null && count($this->calls) >= $this->refuseFromCall)) {
            throw new InvalidStartKey();
        }
        if ($this->answerEmptyBatchWithKey) {
            return new ExpenseBatch([], ['SK' => ExpenseKeys::PREFIX . ExpenseBuilder::uuid(1), 'LSI1SK' => ExpenseKeys::indexKey($from, ExpenseBuilder::uuid(1))]);
        }
        $items = [];
        foreach ($this->newestFirst as $expense) {
            $key = ExpenseKeys::indexKey($expense->date, $expense->id);
            $afterStart = $startKey === null || $key < $startKey['LSI1SK'];
            $inRange = $key >= ExpenseKeys::rangeStart($from) && $key <= ExpenseKeys::rangeEnd($to);
            if ($afterStart && $inRange) {
                $items[] = $expense;
            }
        }
        $batch = array_slice($items, 0, $batchLimit + $this->overDeliverBy);
        $last = $batch === [] ? null : $batch[array_key_last($batch)];

        return new ExpenseBatch(
            $batch,
            $last !== null && count($batch) === $batchLimit
                ? ['SK' => ExpenseKeys::PREFIX . $last->id, 'LSI1SK' => ExpenseKeys::indexKey($last->date, $last->id)]
                : null,
        );
    }
}
