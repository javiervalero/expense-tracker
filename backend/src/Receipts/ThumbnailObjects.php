<?php

declare(strict_types=1);

namespace App\Receipts;

/**
 * What the code may do with THUMBNAILS in the receipt-thumbnails bucket (C13): write and delete, never read. The
 * worker gets this port, not the whole object store.
 * Methods throw {@see \App\Infrastructure\S3\ObjectStoreFailure}.
 */
interface ThumbnailObjects
{
    public function put(ReceiptObjectKey $key, string $jpeg): void;

    public function delete(ReceiptObjectKey $key): void;

    /** Deletes every thumbnail of one expense (its whole folder). */
    public function deleteExpense(string $userId, string $expenseId): void;
}
