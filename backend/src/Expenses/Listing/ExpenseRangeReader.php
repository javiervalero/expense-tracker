<?php

declare(strict_types=1);

namespace App\Expenses\Listing;

use App\Auth\Session\Principal;
use App\Expenses\ExpenseBatch;
use App\Expenses\ExpenseDate;
use App\Expenses\InvalidStartKey;
use App\Infrastructure\DynamoDb\StorageFailure;
use InvalidArgumentException;

/**
 * The seam under {@see ExpenseLister} (B24): one batch of a user's expenses in a day range, newest first, resumable
 * from a start key. {@see \App\Expenses\ExpenseRepository} is the real implementation; unit tests use a fake. Phase 6
 * reads a month through the same method, batch by batch, without the lister's page budget.
 */
interface ExpenseRangeReader
{
    /**
     * @param array<string, string>|null $startKey `SK` and `LSI1SK` of an expense; anything else is refused
     *
     * @throws InvalidStartKey when the start key is not an expense position inside the range
     * @throws InvalidArgumentException when `$from` is after `$to`, or the limit is out of range
     * @throws StorageFailure
     */
    public function pageByDate(Principal $owner, ExpenseDate $from, ExpenseDate $to, ?array $startKey, int $batchLimit): ExpenseBatch;
}
