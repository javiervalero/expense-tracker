<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Auth\AuthKeys;
use App\Auth\Session\Principal;
use App\Expenses\ExpenseKeys;
use App\Expenses\ExpenseNotFound;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\DynamoDb\TransactionOperation;
use App\Infrastructure\Time\StoredTime;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Receipt records (`USER#<u>` / `RCPT#<e>`, C2): one per expense. Items are built and read field by field. A missing or
 * wrongly typed attribute, a key that does not parse, or a key that does not belong to the item's own partition and
 * sort key is a {@see StorageFailure} (`CorruptItem`): a corrupt item can never give a user a URL for another user's file.
 *
 * Every clock value is a `DateTimeImmutable` from the caller, compared as `getTimestamp()` (the stored `expiresAt` is
 * epoch seconds, which is also what DynamoDB's TTL reads), so conditions and {@see Receipt::isLive()} use one clock.
 */
final readonly class ReceiptRepository
{
    /** Position of the expense's condition check in an upload start's transaction (expense first, receipt second). */
    private const int EXPENSE_POSITION = 0;
    private const int RECEIPT_POSITION = 1;
    private const string ETAG_NAME = '#objectEtag';
    /** The SET that fails a record, shared by {@see self::markFailed()} and {@see self::markReplaced()}. */
    private const string FAILED_UPDATE = 'SET #status = :failed, #failureReason = :reason, #expiresAt = :expiresAt, #processedAt = :processedAt';
    private const array FAILED_NAMES = ['#failureReason' => 'failureReason', '#processedAt' => 'processedAt'];
    private const string SETTLE_CONDITION = '#objectKey = :key AND #status = :pending AND #expiresAt > :now';

    public function __construct(private ItemStore $items) {}

    /**
     * C3: a pending or failed record that expired counts as absent whether or not DynamoDB has deleted it yet.
     *
     * @throws InvalidArgumentException when the id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function find(Principal $owner, string $expenseId, DateTimeImmutable $now): ?Receipt
    {
        $receipt = $this->read(ExpenseKeys::receipt($owner, $expenseId), $owner->userId, $expenseId);

        return $receipt !== null && $receipt->isLive($now) ? $receipt : null;
    }

    /**
     * Worker read (C10): the record of the key's user and expense, whatever its key, status or expiry (another upload's,
     * a settled one, an expired one). Callers compare the returned record's key with the one they hold and use
     * {@see Receipt::isLive()} for expiry.
     *
     * @throws StorageFailure
     */
    public function findByKey(ReceiptObjectKey $key): ?Receipt
    {
        return $this->readByKey($key);
    }

    /**
     * C4: reads the current record, then one transaction: the expense must exist (position 0) and the record must still
     * be the one that was read, or absent (position 1). Returns the key of the receipt that was replaced so the
     * caller can delete its objects after the commit, or null when there was none.
     *
     * A corrupt current record makes every start a StorageFailure until the record is removed (deliberate: a read never
     * guesses, and {@see self::remove()} clears it). A `$pending` already past its `expiresAt` is accepted: it is
     * harmless, it reads as absent and DynamoDB's TTL deletes it.
     *
     * @throws ExpenseNotFound
     * @throws ReceiptChanged
     * @throws TransactionCancelled for a cancellation that is not a failed condition
     * @throws InvalidArgumentException when an id is malformed, `$pending` is not pending, its key is not this owner's and this expense's, or it is the receipt that is already current
     * @throws StorageFailure
     */
    public function startUpload(Principal $owner, string $expenseId, Receipt $pending): ?ReceiptObjectKey
    {
        $recordKey = ExpenseKeys::receipt($owner, $expenseId);
        if (
            $pending->status !== ReceiptStatus::Pending
            || $pending->objectKey->userId() !== $owner->userId || $pending->objectKey->expenseId() !== $expenseId
        ) {
            throw new InvalidArgumentException('A new receipt must be pending and belong to this owner and expense.');
        }
        $current = $this->read($recordKey, $owner->userId, $expenseId);
        if ($current !== null && $current->receiptId() === $pending->receiptId()) {
            throw new InvalidArgumentException('A new receipt must not be the one that is already current.');
        }
        $operations = [
            self::EXPENSE_POSITION => TransactionOperation::conditionCheck(ExpenseKeys::expense($owner, $expenseId), 'attribute_exists(PK)'),
            self::RECEIPT_POSITION => $current === null
                ? TransactionOperation::put(self::item($recordKey, $pending), 'attribute_not_exists(PK)')
                : TransactionOperation::put(
                    self::item($recordKey, $pending),
                    'attribute_not_exists(PK) OR receiptId = :old',
                    [],
                    [':old' => $current->receiptId()],
                ),
        ];
        try {
            $this->items->transactWrite($operations);
        } catch (TransactionCancelled $cancelled) {
            throw match (true) {
                self::failed($cancelled, self::EXPENSE_POSITION) => new ExpenseNotFound(),
                self::failed($cancelled, self::RECEIPT_POSITION) => new ReceiptChanged(),
                default => $cancelled,
            };
        }

        return $current?->objectKey;
    }

    /**
     * C7: deletes the record and returns it, so the caller can delete its objects; null when there was none.
     *
     * The deletion is one unconditional `DeleteItem ALL_OLD`, so a corrupt record can always be cleared (see CORRUPT_ITEM
     * below). The returned record may have expired without DynamoDB's TTL having deleted it yet; the caller decides 204
     * or 404 with {@see Receipt::isLive()}.
     *
     * A StorageFailure whose code is `StorageFailure::CORRUPT_ITEM` means the record IS deleted but unreadable. The caller
     * (WP6) then cleans up only THIS expense's prefixes, built from the Principal:
     * `ReceiptObjectKey::originalPrefix($owner->userId, $expenseId)` and the thumbnail prefix. Never the user's whole prefix.
     * Any other StorageFailure (throttling, timeout, access, client error) means the outcome is unknown and the record may
     * still exist: do not clean up, answer 5xx. A retry is safe.
     *
     * @throws InvalidArgumentException when the id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function remove(Principal $owner, string $expenseId): ?Receipt
    {
        $old = $this->items->deleteItemReturningOld(ExpenseKeys::receipt($owner, $expenseId));

        return $old === null ? null : self::hydrate($old, $owner->userId, $expenseId, 'DeleteItem');
    }

    /**
     * C10 step 6: pending to ready with the type the worker detected, only for this upload and only before it expires.
     *
     * @throws ReceiptNotPending the record is missing, another upload's, or an expired pending one
     * @throws StorageFailure
     */
    public function markReady(ReceiptObjectKey $key, ReceiptContentType $detected, DateTimeImmutable $now, ?string $objectEtag): SettleResult
    {
        [$setEtag, $etagNames, $etagValues] = self::etagAssignment($objectEtag);

        return $this->settle($key, fn(): array => $this->items->updateItem(
            self::keyOfRecord($key),
            'SET #status = :ready, #contentType = :detected, #processedAt = :processedAt' . $setEtag . ' REMOVE #expiresAt, #failureReason',
            self::settleNames(['#contentType' => 'contentType', '#processedAt' => 'processedAt', '#failureReason' => 'failureReason'] + $etagNames),
            self::settleValues($key, $now) + [
                ':ready' => ReceiptStatus::Ready->value,
                ':detected' => $detected->value,
                ':processedAt' => StoredTime::format($now),
            ] + $etagValues,
            self::SETTLE_CONDITION,
        ));
    }

    /**
     * C10 step 6: pending to failed (kept {@see ReceiptLimits::FAILED_TTL_SECONDS}), with the same guard as {@see self::markReady()}.
     *
     * @throws ReceiptNotPending the record is missing, another upload's, or an expired pending one
     * @throws StorageFailure
     */
    public function markFailed(ReceiptObjectKey $key, FailureReason $reason, DateTimeImmutable $now, ?string $objectEtag): SettleResult
    {
        [$setEtag, $etagNames, $etagValues] = self::etagAssignment($objectEtag);

        return $this->settle($key, fn(): array => $this->items->updateItem(
            self::keyOfRecord($key),
            self::FAILED_UPDATE . $setEtag,
            self::settleNames(self::FAILED_NAMES + $etagNames),
            self::settleValues($key, $now) + self::failedValues($reason, $now) + $etagValues,
            self::SETTLE_CONDITION,
        ));
    }

    /**
     * Ready to failed, because the object at the key is no longer the one that was inspected: someone wrote other bytes to
     * it with the same upload policy after the receipt was settled. Only while the record is still this upload, `ready`
     * and holding the ETag of the inspected bytes.
     *
     * @throws ReceiptNotPending the condition failed: the caller re-reads the record and decides again
     * @throws StorageFailure
     */
    public function markReplaced(ReceiptObjectKey $key, string $inspectedEtag, FailureReason $reason, DateTimeImmutable $now): void
    {
        try {
            $this->items->updateItem(
                self::keyOfRecord($key),
                self::FAILED_UPDATE,
                self::settleNames(self::FAILED_NAMES + [self::ETAG_NAME => 'objectEtag']),
                self::failedValues($reason, $now) + [
                    ':key' => $key->originalKey(),
                    ':ready' => ReceiptStatus::Ready->value,
                    ':stored' => $inspectedEtag,
                ],
                '#objectKey = :key AND #status = :ready AND #objectEtag = :stored',
            );
        } catch (ConditionFailed) {
            throw new ReceiptNotPending();
        }
    }

    /**
     * What a record keeps of the inspected bytes: the clause to append to a SET, and its names and values (none when
     * there is no ETag, which then is simply not stored).
     *
     * @return array{literal-string, array<string, literal-string>, array<string, string>}
     */
    private static function etagAssignment(?string $objectEtag): array
    {
        if ($objectEtag === null) {
            return ['', [], []];
        }

        return [', #objectEtag = :etag', [self::ETAG_NAME => 'objectEtag'], [':etag' => $objectEtag]];
    }

    /**
     * @return array<string, string|int>
     */
    private static function failedValues(FailureReason $reason, DateTimeImmutable $now): array
    {
        return [
            ':failed' => ReceiptStatus::Failed->value,
            ':reason' => $reason->value,
            ':expiresAt' => $now->getTimestamp() + ReceiptLimits::FAILED_TTL_SECONDS,
            ':processedAt' => StoredTime::format($now),
        ];
    }

    /**
     * C10 step 7: a failed condition is re-read. The same upload, no longer pending, is a duplicate delivery.
     *
     * @param \Closure(): array<string, mixed> $update
     *
     * @throws ReceiptNotPending
     * @throws StorageFailure
     */
    private function settle(ReceiptObjectKey $key, \Closure $update): SettleResult
    {
        try {
            $update();

            return SettleResult::Applied;
        } catch (ConditionFailed) {
            $stored = $this->readByKey($key);
            if ($stored !== null && $stored->objectKey->originalKey() === $key->originalKey() && $stored->status !== ReceiptStatus::Pending) {
                return SettleResult::AlreadyProcessed;
            }

            throw new ReceiptNotPending();
        }
    }

    private function readByKey(ReceiptObjectKey $key): ?Receipt
    {
        return $this->read(self::keyOfRecord($key), $key->userId(), $key->expenseId());
    }

    /**
     * The names of the settle condition (#objectKey, #status, #expiresAt) and those of the update itself.
     *
     * @param array<string, literal-string> $own
     *
     * @return array<string, literal-string>
     */
    private static function settleNames(array $own): array
    {
        return $own + ['#objectKey' => 'objectKey', '#status' => 'status', '#expiresAt' => TableLayout::EXPIRES_AT];
    }

    /**
     * The values of the settle condition (:key, :pending, :now).
     *
     * @return array<string, string|int>
     */
    private static function settleValues(ReceiptObjectKey $key, DateTimeImmutable $now): array
    {
        return [
            ':key' => $key->originalKey(),
            ':pending' => ReceiptStatus::Pending->value,
            ':now' => $now->getTimestamp(),
        ];
    }

    /**
     * @param array{PK: string, SK: string} $recordKey
     *
     * @throws StorageFailure
     */
    private function read(array $recordKey, string $userId, string $expenseId): ?Receipt
    {
        $item = $this->items->getItem($recordKey);

        return $item === null ? null : self::hydrate($item, $userId, $expenseId, 'GetItem');
    }

    /**
     * The worker has no session, so the partition is built from the user id of an object key that parsed (a validated
     * UUID), through the one entry point made for it.
     *
     * @return array{PK: string, SK: string}
     */
    private static function keyOfRecord(ReceiptObjectKey $key): array
    {
        return [
            TableLayout::PK => AuthKeys::receiptOwnerPartition($key->userId()),
            TableLayout::SK => ExpenseKeys::RECEIPT_PREFIX . $key->expenseId(),
        ];
    }

    private static function failed(TransactionCancelled $cancelled, int $position): bool
    {
        return ($cancelled->reasons[$position] ?? null) === TransactionCancelled::CONDITION_CHECK_FAILED;
    }

    /**
     * @param array{PK: string, SK: string} $recordKey
     *
     * @return array<string, mixed>
     */
    private static function item(array $recordKey, Receipt $pending): array
    {
        assert($pending->expiresAt !== null, 'A pending receipt has an expiry.');

        return $recordKey + [
            'receiptId' => $pending->receiptId(),
            'objectKey' => $pending->objectKey->originalKey(),
            'status' => $pending->status->value,
            'contentType' => $pending->contentType->value,
            'size' => $pending->size,
            'createdAt' => StoredTime::format($pending->createdAt),
            TableLayout::EXPIRES_AT => $pending->expiresAt->getTimestamp(),
        ];
    }

    /**
     * Full validation of a stored item: a read never guesses. The partition and sort key must be this user's and this
     * expense's, the object key must parse and round-trip, and the user and expense inside it must be the item's own.
     *
     * @param array<string, mixed> $item
     * @param string $operation the SDK operation that returned the item, for the failure
     *
     * @throws StorageFailure
     */
    private static function hydrate(array $item, string $userId, string $expenseId, string $operation): Receipt
    {
        $stored = $item['objectKey'] ?? null;
        $objectKey = is_string($stored) ? ReceiptObjectKey::parse($stored) : null;
        if (
            $objectKey === null || $objectKey->originalKey() !== $stored
            || ($item[TableLayout::PK] ?? null) !== AuthKeys::receiptOwnerPartition($objectKey->userId())
            || ($item[TableLayout::SK] ?? null) !== ExpenseKeys::RECEIPT_PREFIX . $objectKey->expenseId()
            || $objectKey->userId() !== $userId || $objectKey->expenseId() !== $expenseId
            || ($item['receiptId'] ?? null) !== $objectKey->receiptId()
        ) {
            throw self::corrupt($operation);
        }
        $status = is_string($item['status'] ?? null) ? ReceiptStatus::tryFrom($item['status']) : null;
        $contentType = is_string($item['contentType'] ?? null) ? ReceiptContentType::tryFrom($item['contentType']) : null;
        $size = $item['size'] ?? null;
        $createdAt = StoredTime::parse($item['createdAt'] ?? null);
        $expiresAt = self::optional($item, TableLayout::EXPIRES_AT, static fn(mixed $value): ?DateTimeImmutable => is_int($value) ? new DateTimeImmutable('@' . $value) : null);
        $reason = self::optional($item, 'failureReason', static fn(mixed $value): ?FailureReason => is_string($value) ? FailureReason::tryFrom($value) : null);
        $processedAt = self::optional($item, 'processedAt', StoredTime::parse(...));
        $etag = $item['objectEtag'] ?? null;
        if ($status === null || $contentType === null || !is_int($size) || $createdAt === null || $expiresAt === false || $reason === false || $processedAt === false) {
            throw self::corrupt($operation);
        }
        if ($etag !== null && !is_string($etag)) {
            throw self::corrupt($operation);
        }
        try {
            return new Receipt($objectKey, $status, $contentType, $size, $createdAt, $expiresAt, $reason, $processedAt, $etag);
        } catch (InvalidArgumentException) {
            throw self::corrupt($operation);
        }
    }

    /**
     * @template T of object
     *
     * @param array<string, mixed> $item
     * @param callable(mixed): ?T $parse
     *
     * @return T|null|false null when the attribute is absent, false when it is present and invalid
     */
    private static function optional(array $item, string $name, callable $parse): object|false|null
    {
        if (!array_key_exists($name, $item)) {
            return null;
        }

        return $parse($item[$name]) ?? false;
    }

    private static function corrupt(string $operation): StorageFailure
    {
        return new StorageFailure($operation, StorageFailure::CORRUPT_ITEM);
    }
}
