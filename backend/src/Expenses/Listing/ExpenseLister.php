<?php

declare(strict_types=1);

namespace App\Expenses\Listing;

use App\Auth\Session\Principal;
use App\Categories\CategoryResolver;
use App\Expenses\ExpenseBatch;
use App\Expenses\InvalidStartKey;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use InvalidArgumentException;
use LogicException;

/**
 * One page of `GET /expenses` (B17, B18): reads the user's expenses newest first in batches, keeps the ones that match
 * the filter, and stops at the `limit`-th match, at the end of the range, or after {@see self::EXAMINE_BUDGET}
 * examined items, which bounds the cost of any request whatever the filter.
 *
 * The cursor is built from the last EXAMINED item, never from the batch's last key: after a stop in the middle of a
 * batch the batch key would skip the items between. `nextCursor` is null only when the range is exhausted, so a page
 * that follows a non-null cursor can be empty (see {@see ExpensePage}). The partition is the principal's, and the
 * resolver must be that principal's too: {@see CategoryResolver::assertFor()} is the first thing `list()` does. The
 * resolver is a per-request snapshot and a StorageFailure from it ends the request. A category uuid that is not the
 * caller's gives the empty page without reading expenses.
 */
final readonly class ExpenseLister
{
    public const int LIMIT_DEFAULT = 50;
    public const int LIMIT_MAX = 100;
    public const int BATCH = 100;
    public const int EXAMINE_BUDGET = 1000;

    public function __construct(private ExpenseRangeReader $reader) {}

    /**
     * @throws InvalidQuery when the reader refuses the cursor's position
     * @throws InvalidArgumentException when the principal's user id is not a lower-case UUID
     * @throws LogicException when the resolver was built for another principal
     * @throws StorageFailure
     */
    public function list(Principal $owner, ExpenseFilter $filter, CategoryResolver $categories): ExpensePage
    {
        $categories->assertFor($owner);
        if ($filter->category?->id !== null && !$categories->has($filter->category->id)) {
            return new ExpensePage([], null);
        }
        $matches = [];
        $last = null;
        $examined = 0;
        $ended = false;
        $startKey = $filter->cursor?->startKey();
        $startKeyIsFromClient = $filter->cursor !== null;
        // Each round examines at least one item (an empty batch with a continuation key is refused), so the budget bounds it.
        while (!$ended && $examined < self::EXAMINE_BUDGET && count($matches) < $filter->limit) {
            $batch = $this->read($owner, $filter, $startKey, self::batchSize($filter, $examined, count($matches)), $startKeyIsFromClient);
            $consumed = 0;
            foreach ($batch->items as $expense) {
                ++$consumed;
                $last = $expense;
                if ($filter->matches($expense, $categories)) {
                    $matches[] = $expense;
                }
                if (count($matches) === $filter->limit) {
                    break;
                }
            }
            $examined += $consumed;
            $ended = $batch->lastKey === null && $consumed === count($batch->items);
            $startKey = $batch->lastKey;
            $startKeyIsFromClient = false;
        }

        return new ExpensePage($matches, $ended || $last === null ? null : $filter->cursorAfter($last));
    }

    /** Full batches when a PHP filter may reject items; otherwise exactly what is still needed. */
    private static function batchSize(ExpenseFilter $filter, int $examined, int $matched): int
    {
        return min(
            self::BATCH,
            self::EXAMINE_BUDGET - $examined,
            $filter->hasPhpFilters() ? self::BATCH : $filter->limit - $matched,
        );
    }

    /**
     * @param array<string, string>|null $startKey
     * @param bool $startKeyIsFromClient only the key of the client's cursor can be the client's fault; a key taken
     *                                   from a batch is the server's, and a refusal of it is a server error
     *
     * @throws InvalidQuery when the reader refuses the client's start key
     * @throws InvalidStartKey when the reader refuses a key that came from one of its own batches
     * @throws StorageFailure
     */
    private function read(Principal $owner, ExpenseFilter $filter, ?array $startKey, int $batchLimit, bool $startKeyIsFromClient): ExpenseBatch
    {
        try {
            $batch = $this->reader->pageByDate($owner, $filter->from, $filter->to, $startKey, $batchLimit);
        } catch (InvalidStartKey $refused) {
            throw $startKeyIsFromClient ? InvalidQuery::cursor() : $refused;
        }
        if (count($batch->items) > $batchLimit) {
            throw new StorageFailure(ItemStore::QUERY_OPERATION, StorageFailure::CORRUPT_ITEM);
        }
        if ($batch->items === [] && $batch->lastKey !== null) {
            // Not possible without a filter expression; refused so that a misbehaving store cannot make the loop spin.
            throw new StorageFailure(ItemStore::QUERY_OPERATION, StorageFailure::CORRUPT_ITEM);
        }

        return $batch;
    }
}
