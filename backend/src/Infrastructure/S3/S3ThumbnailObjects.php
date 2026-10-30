<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use App\Infrastructure\Clock\Deadline;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ThumbnailObjects;

/** {@see ThumbnailObjects} in the thumbnails bucket of the settings (not a free string, so the buckets cannot be swapped). */
final readonly class S3ThumbnailObjects implements ThumbnailObjects
{
    private const CONTENT_TYPE = 'image/jpeg';

    private string $bucket;

    public function __construct(private ObjectStore $store, S3Settings $settings)
    {
        $this->bucket = $settings->thumbnailsBucket;
    }

    public function put(ReceiptObjectKey $key, string $jpeg): void
    {
        $this->store->put($this->bucket, $key->thumbnailKey(), $jpeg, self::CONTENT_TYPE);
    }

    public function delete(ReceiptObjectKey $key): void
    {
        $this->store->delete($this->bucket, $key->thumbnailKey());
    }

    public function deleteExpense(string $userId, string $expenseId, ?Deadline $deadline = null): void
    {
        $this->store->deletePrefix($this->bucket, ReceiptObjectKey::thumbnailPrefix($userId, $expenseId), $deadline);
    }
}
