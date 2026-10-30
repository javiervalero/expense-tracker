<?php

declare(strict_types=1);

namespace App\Receipts\Processing;

use App\Infrastructure\Clock\Clock;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\ObjectStoreFailure;
use App\Infrastructure\S3\S3Settings;
use App\Queue\RecordProcessor;
use App\Receipts\FailureReason;
use App\Receipts\InvalidS3Event;
use App\Receipts\OriginalObjects;
use App\Receipts\Receipt;
use App\Receipts\ReceiptNotPending;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptObjectMissing;
use App\Receipts\ReceiptRepository;
use App\Receipts\ReceiptStatus;
use App\Receipts\S3EventParser;
use App\Receipts\S3ObjectRef;
use App\Receipts\SettleResult;
use App\Receipts\ThumbnailObjects;
use ErrorException;

/**
 * One queue message, one S3 event, per C10 (amended in the WP8 review, see the plan):
 *
 * 1. an object outside the receipts bucket, or whose key is not exactly `receipts/<uuid>/<uuid>/<uuid>`, is logged and
 *    skipped (a success: retrying would not change it);
 * 2. the record of that user and expense must be the pending, unexpired one of THIS key; otherwise go to 7;
 * 3. the file is read (at most {@see \App\Receipts\ReceiptLimits::MAX_BYTES}) together with its ETag. A file that is not
 *    there while the record is still ours is an error, so the message is retried; an oversize file is refused as `too-large`;
 * 4. {@see FileInspector} decides whether it is the receipt the client declared;
 * 5. only an accepted image reaches the {@see ThumbnailRenderer}; its JPEG is written before the record changes. A
 *    thumbnail that cannot be made is a failed receipt (`unreadable`), not a retry;
 * 6. the record moves to `ready` by a conditional update that also keeps the ETag of the inspected bytes. An invalid file
 *    moves it to `failed` FIRST and only then has its original deleted, so a storage error never leaves a pending record
 *    without its file; a retry after a failed delete finds a failed record and deletes the orphan;
 * 7. a lost race, or a record that is gone, replaced or expired: the objects of THIS key are deleted (idempotent). A
 *    record of this very upload that is already settled is a duplicate delivery, with one check: the upload policy lasts 5
 *    minutes and can be used again, so a `failed` record deletes whatever is at the key, and a `ready` one compares the
 *    ETag of the object stored now with the one inspected. Other bytes there were never inspected: the record is failed
 *    and both files are deleted. A run whose own update lost to another run (`AlreadyProcessed`) may have read other
 *    bytes than that run settled with, so it re-reads the record and decides the same way (this also removes a thumbnail
 *    it wrote over the other run's).
 *
 * Any S3 or DynamoDB exception is thrown, so the batch processor reports this message for redelivery and, after the
 * queue's limit, the dead-letter queue. An invalid file is never an error. Logs carry message id, bucket, key, status,
 * reason and timing; never file bytes.
 */
final readonly class ReceiptProcessor implements RecordProcessor
{
    private const float NANOSECONDS_PER_MILLISECOND = 1_000_000.0;

    private string $receiptsBucket;

    public function __construct(
        private S3EventParser $parser,
        private ReceiptRepository $receipts,
        private OriginalObjects $originals,
        private ThumbnailObjects $thumbnails,
        private FileInspector $inspector,
        private ThumbnailRenderer $thumbnailMaker,
        private JsonLogger $logger,
        private Clock $clock,
        S3Settings $settings,
    ) {
        $this->receiptsBucket = $settings->receiptsBucket;
    }

    public function process(array $record): void
    {
        $body = $record['body'] ?? null;
        if (!\is_string($body)) {
            throw new InvalidS3Event('The SQS record has no string body.');
        }

        $messageId = $record['messageId'] ?? null;
        $objects = $this->parser->parse($body);
        if ($objects === []) {
            $this->logger->info('s3 event without objects ignored', ['messageId' => $messageId]);

            return;
        }
        foreach ($objects as $object) {
            $this->processObject($object, $messageId);
        }
    }

    /**
     * Like the front controller's: an error or warning that `error_reporting` lets through becomes an exception, one
     * silenced with `@` (such as the thumbnail maker's) is left to PHP.
     *
     * @throws ErrorException
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    private function processObject(S3ObjectRef $object, mixed $messageId): void
    {
        $key = $object->bucket === $this->receiptsBucket ? ReceiptObjectKey::parse($object->key) : null;
        if ($key === null) {
            $this->logger->info('receipt event skipped', ['messageId' => $messageId, 'bucket' => $object->bucket, 'key' => $object->key]);

            return;
        }
        $context = ['messageId' => $messageId, 'bucket' => $object->bucket, 'key' => $key->originalKey()];

        $current = $this->receipts->findByKey($key);
        if ($current === null || !self::isLivePendingUploadOf($current, $key, $this->clock->now())) {
            $this->settledElsewhere($key, $current, $context);

            return;
        }
        $this->processPending($key, $current, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processPending(ReceiptObjectKey $key, Receipt $receipt, array $context): void
    {
        $started = hrtime(true);
        try {
            $object = $this->originals->fetch($key);
        } catch (ObjectStoreFailure $failure) {
            if ($failure->errorCode !== ObjectStoreFailure::TOO_LARGE) {
                throw $failure;
            }
            $this->reject($key, FailureReason::TooLarge, $context, $started, null);

            return;
        }
        if ($object === null) {
            $this->objectIsMissing($key, $context);

            return;
        }

        $etag = $object->etag;
        $inspection = $this->inspector->inspect($object->body, $receipt->contentType);
        $type = $inspection->type;
        if ($type === null) {
            $this->reject($key, $inspection->failure ?? FailureReason::Unreadable, $context, $started, $etag);

            return;
        }
        if ($type->isImage()) {
            $thumbnail = $this->thumbnailOf($object->body, $context);
            if ($thumbnail === null) {
                $this->reject($key, FailureReason::Unreadable, $context, $started, $etag);

                return;
            }
            $this->thumbnails->put($key, $thumbnail);
            unset($thumbnail);
        }
        unset($object); // before the next object: the memory of a big image is not needed any more

        $this->settle(
            $key,
            fn(): SettleResult => $this->receipts->markReady($key, $type, $this->clock->now(), $etag),
            'receipt ready',
            $context + ['status' => ReceiptStatus::Ready->value, 'contentType' => $type->value, 'width' => $inspection->width, 'height' => $inspection->height] + self::timing($started),
        );
    }

    /**
     * Step 6, invalid file: the record says why first, then the original goes (the amended order).
     *
     * @param array<string, mixed> $context
     */
    private function reject(ReceiptObjectKey $key, FailureReason $reason, array $context, int $started, ?string $etag): void
    {
        $this->settle(
            $key,
            fn(): SettleResult => $this->receipts->markFailed($key, $reason, $this->clock->now(), $etag),
            'receipt failed',
            $context + ['status' => ReceiptStatus::Failed->value, 'failureReason' => $reason->value] + self::timing($started),
            fn() => $this->originals->delete($key),
        );
    }

    /**
     * @param \Closure(): SettleResult $update
     * @param array<string, mixed> $context
     * @param (\Closure(): void)|null $afterApplied runs once this call has moved the record, before it is logged
     */
    private function settle(ReceiptObjectKey $key, \Closure $update, string $message, array $context, ?\Closure $afterApplied = null): void
    {
        try {
            $result = $update();
        } catch (ReceiptNotPending) {
            $this->discard($key, $context);

            return;
        }
        if ($result === SettleResult::AlreadyProcessed) {
            // Another run settled this record first, perhaps for other bytes than the ones this run read (the upload
            // policy can be used twice). Decide from the record as it is now, with the ETag check.
            $this->settledElsewhere($key, $this->receipts->findByKey($key), $context);

            return;
        }
        if ($afterApplied !== null) {
            $afterApplied();
        }
        $this->logger->info($message, $context);
    }

    /**
     * Step 3: the file is not there. If the record is still this pending upload, retry later (the object may not be
     * visible yet); otherwise it is step 7.
     *
     * @param array<string, mixed> $context
     */
    private function objectIsMissing(ReceiptObjectKey $key, array $context): void
    {
        $current = $this->receipts->findByKey($key);
        if ($current !== null && self::isLivePendingUploadOf($current, $key, $this->clock->now())) {
            throw new ReceiptObjectMissing();
        }
        $this->settledElsewhere($key, $current, $context);
    }

    /**
     * Step 7. A record of this upload that is settled and not expired is a duplicate delivery (see {@see self::verifySettled()}
     * for what it still checks). A record that is missing, has another key or has expired leaves these objects without
     * an owner.
     *
     * @param array<string, mixed> $context
     */
    private function settledElsewhere(ReceiptObjectKey $key, ?Receipt $current, array $context): void
    {
        $settled = $this->settledOrClean($key, $current, $context);
        if ($settled === null || ($settled->status === ReceiptStatus::Ready && $this->verifySettled($key, $settled, $context))) {
            return;
        }
        $this->logAlreadyProcessed($settled, $context);
    }

    /**
     * The same decision as {@see self::settledElsewhere()} but never checks an ETag: for a record that a failed
     * {@see self::verifySettled()} found changed, so one message cannot loop on a record that keeps changing.
     *
     * @param array<string, mixed> $context
     */
    private function settledWithoutVerifying(ReceiptObjectKey $key, ?Receipt $current, array $context): void
    {
        $settled = $this->settledOrClean($key, $current, $context);
        if ($settled !== null) {
            $this->logAlreadyProcessed($settled, $context);
        }
    }

    /**
     * The record when it is this upload's, settled and not expired (a `failed` one first has whatever is at the key
     * deleted: nothing valid is stored for a failed receipt, so it came after the check). Any other record leaves these
     * objects without an owner: they are deleted and null is returned.
     *
     * @param array<string, mixed> $context
     */
    private function settledOrClean(ReceiptObjectKey $key, ?Receipt $current, array $context): ?Receipt
    {
        if ($current === null || $current->objectKey->originalKey() !== $key->originalKey() || !$current->isLive($this->clock->now())) {
            $this->discard($key, $context);

            return null;
        }
        if ($current->status === ReceiptStatus::Failed) {
            $this->originals->delete($key);
            $this->thumbnails->delete($key);
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAlreadyProcessed(Receipt $settled, array $context): void
    {
        $this->logger->info('receipt already processed', $context + ['status' => $settled->status->value]);
    }

    /**
     * A `ready` record holds the ETag of the bytes that were inspected. The ETag of the object stored now (a HEAD, not
     * the event's, which can arrive out of order) must be the same: the upload policy can be used again for 5 minutes, and
     * other bytes were never inspected. Then the record is failed (first, like step 6) and both files are deleted.
     *
     * @param array<string, mixed> $context
     *
     * @return bool true when the record was failed and the files deleted (the caller has nothing left to log)
     */
    private function verifySettled(ReceiptObjectKey $key, Receipt $current, array $context): bool
    {
        $inspected = $current->objectEtag;
        $stored = $inspected === null ? null : $this->originals->etag($key); // no ETag recorded: nothing to compare
        if ($inspected === null || $stored === null || $stored === $inspected) {
            return false;
        }
        try {
            $this->receipts->markReplaced($key, $inspected, FailureReason::Unreadable, $this->clock->now());
        } catch (ReceiptNotPending) {
            // Someone else changed the record: decide again from what it is now, without checking a second time.
            $this->settledWithoutVerifying($key, $this->receipts->findByKey($key), $context);

            return true;
        }
        $this->originals->delete($key);
        $this->thumbnails->delete($key);
        $this->logger->info('receipt replaced after check', $context + ['status' => ReceiptStatus::Failed->value, 'failureReason' => FailureReason::Unreadable->value]);

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function discard(ReceiptObjectKey $key, array $context): void
    {
        $this->originals->delete($key);
        $this->thumbnails->delete($key);
        $this->logger->info('receipt obsolete, objects removed', $context);
    }

    /**
     * The JPEG, or null when it cannot be made. That is deterministic for an accepted file, so the receipt fails instead
     * of being retried; only the class of the exception is logged, since its message could quote the file.
     *
     * @param array<string, mixed> $context
     */
    private function thumbnailOf(string $bytes, array $context): ?string
    {
        set_error_handler([self::class, 'handleError']);
        try {
            return $this->thumbnailMaker->make($bytes);
        } catch (\RuntimeException|ErrorException $failure) { // the latter is what handleError throws
            $this->logger->error('thumbnail failed', $context + ['exceptionClass' => $failure::class]);

            return null;
        } finally {
            restore_error_handler();
        }
    }

    private static function isLivePendingUploadOf(Receipt $receipt, ReceiptObjectKey $key, \DateTimeImmutable $now): bool
    {
        return $receipt->objectKey->originalKey() === $key->originalKey()
            && $receipt->status === ReceiptStatus::Pending
            && $receipt->isLive($now);
    }

    /**
     * @return array{durationMs: float}
     */
    private static function timing(int $started): array
    {
        return ['durationMs' => round((hrtime(true) - $started) / self::NANOSECONDS_PER_MILLISECOND, 1)];
    }
}
