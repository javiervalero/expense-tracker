<?php

declare(strict_types=1);

namespace App\Expenses;

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
}
