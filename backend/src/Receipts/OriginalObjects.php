<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Infrastructure\Clock\Deadline;

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

    /**
     * Deletes the originals of one expense (its folder, up to the store's page limit), stopping once the deadline has passed.
     *
     * @param Deadline|null $deadline null: only the page limit applies
     */
    public function deleteExpense(string $userId, string $expenseId, ?Deadline $deadline = null): void;
}
