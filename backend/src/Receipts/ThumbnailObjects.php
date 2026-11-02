<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Infrastructure\Clock\Deadline;

/**
 * What the code may do with THUMBNAILS in the receipt-thumbnails bucket (C13): write and delete, never read. The
 * worker gets this port, not the whole object store.
 * Methods throw {@see \App\Infrastructure\S3\ObjectStoreFailure}.
 */
interface ThumbnailObjects
{
    public function put(ReceiptObjectKey $key, string $jpeg): void;

    public function delete(ReceiptObjectKey $key): void;

    /**
     * Deletes the thumbnails of one expense (its folder, up to the store's page limit), stopping once the deadline has passed.
     *
     * @param Deadline|null $deadline null: only the page limit applies
     */
    public function deleteExpense(string $userId, string $expenseId, ?Deadline $deadline = null): void;
}
