<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\ObjectStoreFailure;
use App\Infrastructure\S3\S3Settings;

/**
 * Removes the files of a receipt that is already gone from the database (C9). The user's intent (the record is gone)
 * is done, so a failing S3 call, or an id that cannot make a key, is logged and NOT rethrown: one ERROR line
 * `receipt cleanup failed` with the bucket and the keys (or the folder prefix) and the safe failure fields, never a
 * URL, policy or signature. Phase 7 puts an alarm on that message. The original and the thumbnail are tried
 * separately, so one failure does not spare the other file.
 */
final readonly class ReceiptObjectCleaner
{
    private const FAILURE_MESSAGE = 'receipt cleanup failed';

    private string $receiptsBucket;
    private string $thumbnailsBucket;

    public function __construct(
        private OriginalObjects $originals,
        private ThumbnailObjects $thumbnails,
        private JsonLogger $logger,
        S3Settings $settings,
    ) {
        $this->receiptsBucket = $settings->receiptsBucket;
        $this->thumbnailsBucket = $settings->thumbnailsBucket;
    }

    public function removeReceipt(ReceiptObjectKey $key): void
    {
        $this->attempt($this->receiptsBucket, fn(): string => $key->originalKey(), fn() => $this->originals->delete($key));
        $this->attempt($this->thumbnailsBucket, fn(): string => $key->thumbnailKey(), fn() => $this->thumbnails->delete($key));
    }

    /**
     * @param string $userId should be a user id (a bad one is logged, not thrown)
     * @param string $expenseId should be an expense id (a bad one is logged, not thrown)
     */
    public function removeExpense(string $userId, string $expenseId): void
    {
        $this->attempt(
            $this->receiptsBucket,
            fn(): string => ReceiptObjectKey::originalPrefix($userId, $expenseId),
            fn() => $this->originals->deleteExpense($userId, $expenseId),
        );
        $this->attempt(
            $this->thumbnailsBucket,
            fn(): string => ReceiptObjectKey::thumbnailPrefix($userId, $expenseId),
            fn() => $this->thumbnails->deleteExpense($userId, $expenseId),
        );
    }

    /**
     * The key or prefix is built INSIDE the guard, so a bad id cannot escape after the record is gone.
     *
     * @param \Closure(): string $keyOrPrefix
     * @param \Closure(): void $deletion
     */
    private function attempt(string $bucket, \Closure $keyOrPrefix, \Closure $deletion): void
    {
        $keys = [];
        try {
            $keys = [$keyOrPrefix()];
            $deletion();
        } catch (ObjectStoreFailure $failure) {
            $this->logger->error(self::FAILURE_MESSAGE, [
                'bucket' => $bucket,
                'keys' => $keys,
                'operation' => $failure->operation,
                'errorCode' => $failure->errorCode,
                'statusCode' => $failure->statusCode,
                'requestId' => $failure->requestId,
                'exceptionClass' => $failure->exceptionClass,
                'errorCount' => $failure->errorCount,
            ]);
        } catch (\InvalidArgumentException $invalid) {
            // Only the class: the message would repeat the bad id.
            $this->logger->error(self::FAILURE_MESSAGE, [
                'bucket' => $bucket,
                'keys' => [],
                'errorCode' => 'InvalidId',
                'exceptionClass' => $invalid::class,
            ]);
        }
    }
}
