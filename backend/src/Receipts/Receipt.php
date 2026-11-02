<?php

declare(strict_types=1);

namespace App\Receipts;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A receipt record (C2): the declared type and size (the worker checks the real file), the status, and when a
 * pending or failed record expires. A ready record never expires; only a failed one has a reason; only a pending one
 * has not been processed. A settled one may carry the ETag of the object the worker actually inspected (without quotes),
 * so a later write to the same key can be told from a duplicate delivery. The receipt id is the key's. Immutable.
 */
final readonly class Receipt
{
    /**
     * @throws InvalidArgumentException for a size outside 1..{@see ReceiptLimits::MAX_BYTES}, a ready record with an
     *                                  expiry, a pending or failed one without or expiring at or before its creation,
     *                                  a failure reason not exactly on failed records, or a processing time not
     *                                  exactly on settled (ready or failed) records, or an ETag that is empty or on a
     *                                  pending record
     */
    public function __construct(
        public ReceiptObjectKey $objectKey,
        public ReceiptStatus $status,
        public ReceiptContentType $contentType,
        public int $size,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
        public ?FailureReason $failureReason,
        public ?DateTimeImmutable $processedAt,
        public ?string $objectEtag = null,
    ) {
        if ($size < 1 || $size > ReceiptLimits::MAX_BYTES) {
            throw new InvalidArgumentException('A receipt size must be between 1 and ' . ReceiptLimits::MAX_BYTES . ' bytes.');
        }
        if ($status === ReceiptStatus::Ready && $expiresAt !== null) {
            throw new InvalidArgumentException('A ready receipt has no expiry.');
        }
        if ($status !== ReceiptStatus::Ready && $expiresAt === null) {
            throw new InvalidArgumentException('A pending or failed receipt needs an expiry.');
        }
        if ($expiresAt !== null && $expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A receipt must expire after it was created.');
        }
        if ($status === ReceiptStatus::Failed && $failureReason === null) {
            throw new InvalidArgumentException('A failed receipt needs a failure reason.');
        }
        if ($status !== ReceiptStatus::Failed && $failureReason !== null) {
            throw new InvalidArgumentException('Only a failed receipt has a failure reason.');
        }
        if (($status === ReceiptStatus::Pending) !== ($processedAt === null)) {
            throw new InvalidArgumentException('A receipt has been processed exactly when it is no longer pending.');
        }
        if ($objectEtag !== null && ($objectEtag === '' || $status === ReceiptStatus::Pending)) {
            throw new InvalidArgumentException('Only a settled receipt has an object ETag, and it is not empty.');
        }
    }

    public function receiptId(): string
    {
        return $this->objectKey->receiptId();
    }

    /** C3: an expired pending or failed record counts as absent whether or not DynamoDB has deleted it yet. */
    public function isLive(DateTimeImmutable $now): bool
    {
        return $this->expiresAt === null || $this->expiresAt > $now;
    }
}
