<?php

declare(strict_types=1);

namespace App\Expenses;

use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;

/**
 * One batch of a descending date query (B18): the expenses in order and the position to continue from. A non-null
 * `lastKey` means there may be more: pass it back as the start key. It holds the `SK` and `LSI1SK` of the last item
 * the query evaluated; the partition is never part of it.
 */
final readonly class ExpenseBatch
{
    /**
     * @param list<Expense> $items
     * @param array{SK: string, LSI1SK: string}|null $lastKey
     */
    public function __construct(public array $items, public ?array $lastKey) {}

    /**
     * The guards every reader of a batch applies: a store that hands back more than the limit, or an empty batch with a
     * continuation key (not possible without a filter expression; refused so that a misbehaving store cannot make a
     * paging loop spin), is corrupt.
     *
     * @throws StorageFailure
     */
    public function checkedAgainst(int $batchLimit): self
    {
        if (count($this->items) > $batchLimit || ($this->items === [] && $this->lastKey !== null)) {
            throw new StorageFailure(ItemStore::QUERY_OPERATION, StorageFailure::CORRUPT_ITEM);
        }

        return $this;
    }
}
