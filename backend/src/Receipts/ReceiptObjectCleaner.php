<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Infrastructure\Clock\Clock;
use App\Infrastructure\Clock\Deadline;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\ObjectStoreFailure;
use App\Infrastructure\S3\S3Settings;

/**
 * Removes the files of a receipt that is already gone from the database (C9). The user's intent (the record is gone)
 * is done, so a failing S3 call, or an id that cannot make a key, is logged and NOT rethrown: one ERROR line
 * `receipt cleanup failed` with the bucket and the keys (or the folder prefix) and the safe failure fields, never a
 * URL, policy or signature. Phase 7 puts an alarm on that message. The original and the thumbnail are tried
 * separately, so one failure does not spare the other file.
 *
 * Time budget (C9 needs the log line to be written, so the work must end before API Gateway's 30 s and the Lambda
 * timeout): each call to removeReceipt() or removeExpense() gets {@see self::BUDGET_SECONDS} (8 s) from the injected
 * clock, shared by all its S3 calls. The deadline is checked before every S3 call here and before every page of a folder
 * in the store (also between a page's listing and its DeleteObjects); once it has passed, nothing more is sent and what
 * was left is logged with errorCode `DeadlineReached`. A call already in flight is not interrupted, so the worst case is
 * the budget plus one call. The cleanup client ({@see \App\Infrastructure\S3\S3ClientFactory::forCleanup()}) allows
 * 5 s per attempt (2 s of it to connect) and 2 attempts, so one call takes at most about 12 s (5 s, up to about 2 s of
 * SDK retry wait, 5 s). The request is then at most about 20 s of cleanup (8 + 12), under API Gateway's 30 s. The store
 * also handles at most 3 pages (3000 keys) per folder.
 */
final readonly class ReceiptObjectCleaner
{
    public const int BUDGET_SECONDS = 8;
    private const string FAILURE_MESSAGE = 'receipt cleanup failed';
    private const string DEADLINE_REACHED = 'DeadlineReached';

    private string $receiptsBucket;
    private string $thumbnailsBucket;

    public function __construct(
        private OriginalObjects $originals,
        private ThumbnailObjects $thumbnails,
        private JsonLogger $logger,
        S3Settings $settings,
        private Clock $clock,
    ) {
        $this->receiptsBucket = $settings->receiptsBucket;
        $this->thumbnailsBucket = $settings->thumbnailsBucket;
    }

    public function removeReceipt(ReceiptObjectKey $key): void
    {
        $deadline = Deadline::after($this->clock, self::BUDGET_SECONDS);
        $this->attempt($deadline, $this->receiptsBucket, fn(): string => $key->originalKey(), fn() => $this->originals->delete($key));
        $this->attempt($deadline, $this->thumbnailsBucket, fn(): string => $key->thumbnailKey(), fn() => $this->thumbnails->delete($key));
    }

    /**
     * @param string $userId should be a user id (a bad one is logged, not thrown)
     * @param string $expenseId should be an expense id (a bad one is logged, not thrown)
     */
    public function removeExpense(string $userId, string $expenseId): void
    {
        $deadline = Deadline::after($this->clock, self::BUDGET_SECONDS);
        $this->attempt(
            $deadline,
            $this->receiptsBucket,
            fn(): string => ReceiptObjectKey::originalPrefix($userId, $expenseId),
            fn() => $this->originals->deleteExpense($userId, $expenseId, $deadline),
        );
        $this->attempt(
            $deadline,
            $this->thumbnailsBucket,
            fn(): string => ReceiptObjectKey::thumbnailPrefix($userId, $expenseId),
            fn() => $this->thumbnails->deleteExpense($userId, $expenseId, $deadline),
        );
    }

    /**
     * The key or prefix is built INSIDE the guard, so a bad id cannot escape after the record is gone. A deadline that
     * has passed is logged like any other failure (with the key or prefix that was left) and sends nothing.
     *
     * @param \Closure(): string $keyOrPrefix
     * @param \Closure(): void $deletion
     */
    private function attempt(Deadline $deadline, string $bucket, \Closure $keyOrPrefix, \Closure $deletion): void
    {
        $keys = [];
        try {
            $keys = [$keyOrPrefix()];
            if ($deadline->hasPassed()) {
                throw new ObjectStoreFailure('Cleanup', self::DEADLINE_REACHED);
            }
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
