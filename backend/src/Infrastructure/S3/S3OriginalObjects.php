<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use App\Infrastructure\Clock\Deadline;
use App\Receipts\OriginalObjects;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectKey;

/** {@see OriginalObjects} in the receipts bucket of the settings (not a free string, so the buckets cannot be swapped). */
final readonly class S3OriginalObjects implements OriginalObjects
{
    private string $bucket;

    public function __construct(private ObjectStore $store, S3Settings $settings)
    {
        $this->bucket = $settings->receiptsBucket;
    }

    public function fetch(ReceiptObjectKey $key): ?StoredObject
    {
        // Nothing the browser could upload is bigger (the policy's content-length-range); more means a wrong object.
        return $this->store->getObject($this->bucket, $key->originalKey(), ReceiptLimits::MAX_BYTES);
    }

    public function etag(ReceiptObjectKey $key): ?string
    {
        return $this->store->etag($this->bucket, $key->originalKey());
    }

    public function delete(ReceiptObjectKey $key): void
    {
        $this->store->delete($this->bucket, $key->originalKey());
    }

    public function deleteExpense(string $userId, string $expenseId, ?Deadline $deadline = null): void
    {
        $this->store->deletePrefix($this->bucket, ReceiptObjectKey::originalPrefix($userId, $expenseId), $deadline);
    }
}
