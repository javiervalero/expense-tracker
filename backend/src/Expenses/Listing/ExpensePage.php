<?php

declare(strict_types=1);

namespace App\Expenses\Listing;

use App\Expenses\Expense;

/**
 * One page of a list (B18, B20): the matching expenses, newest first, and the opaque cursor of the next page.
 * `nextCursor` is null only when the range is exhausted. A non-null cursor does not promise more items: the last
 * page can be empty (the page filled exactly at the end of a full batch, or the read budget ended on non-matches),
 * so a client must follow cursors until `nextCursor` is null.
 */
final readonly class ExpensePage
{
    /** @param list<Expense> $items */
    public function __construct(public array $items, public ?string $nextCursor) {}
}
