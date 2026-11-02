<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * One row of a monthly report (D8): what the month's expenses of one category add up to. `categoryId` and `name` are
 * both null for the Uncategorized row (no category, a deleted one, or one that is not the owner's); the client labels it.
 */
final readonly class CategoryTotal
{
    public function __construct(
        public ?string $categoryId,
        public ?string $name,
        public int $totalCents,
        public int $expenseCount,
    ) {}
}
