<?php

declare(strict_types=1);

namespace App\Receipts;

/**
 * What the code may do with the ORIGINAL uploads in the receipts bucket (C13): read and delete, never write (originals
 * arrive only by the browser's presigned POST). The worker and the API get this port, not the whole object store.
 * Methods throw {@see \App\Infrastructure\S3\ObjectStoreFailure}.
 */
interface OriginalObjects
{
    /** @return string|null the file's bytes, or null when it is gone */
    public function get(ReceiptObjectKey $key): ?string;

    public function delete(ReceiptObjectKey $key): void;

    /** Deletes every original of one expense (its whole folder). */
    public function deleteExpense(string $userId, string $expenseId): void;
}
