<?php

declare(strict_types=1);

namespace App\Categories;

use App\Auth\AuthKeys;
use App\Auth\Session\Principal;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StaleVersion;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\DynamoDb\TransactionOperation;
use App\Infrastructure\Id\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

/**
 * Categories in the user's partition. Items are built and read field by field: `PK`, `SK` and `nameKey` stay inside
 * this class and {@see Category}. A missing or wrongly typed attribute is a {@see StorageFailure} (`CorruptItem`).
 */
final readonly class CategoryRepository
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s\Z';
    private const string CONDITION_CHECK_FAILED = 'ConditionalCheckFailed';
    /** A create: one try, and one more after a stale or released lock was dealt with (B13). */
    private const int MAX_CREATE_ATTEMPTS = 2;
    /** A rename: one try, one more without the old-lock delete, one more after a stale or released new lock. */
    private const int MAX_RENAME_ATTEMPTS = 3;
    /** A delete: one try, and one more after a re-read (B14). */
    private const int MAX_DELETE_ATTEMPTS = 2;
    /** Position of the name lock in a create's transaction (category first, lock second). */
    private const int CREATE_LOCK_POSITION = 1;
    /** Positions in a rename's transaction: category, new lock, old lock (the old lock is left out of a resend). */
    private const int RENAME_CATEGORY_POSITION = 0;
    private const int RENAME_NEW_LOCK_POSITION = 1;
    private const int RENAME_OLD_LOCK_POSITION = 2;

    public function __construct(private ItemStore $items) {}

    /**
     * B13: a failed lock is re-read before it is reported. A lock that holds our own new id is our earlier attempt
     * (the SDK retried a write that went through): success. A lock whose category no longer exists is stale: it is
     * removed conditionally and the create is tried once more.
     *
     * @throws CategoryNameTaken another category of the user holds the name
     * @throws TransactionCancelled for any other cancellation (the caller maps overload to 503)
     * @throws InvalidArgumentException when an id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function create(Principal $owner, Category $category): void
    {
        $lockKey = CategoryKeys::nameLockOfStoredKey($owner, $category->nameKey);
        $last = null;
        for ($attempt = 1; $attempt <= self::MAX_CREATE_ATTEMPTS; ++$attempt) {
            try {
                $this->items->transactWrite([
                    TransactionOperation::put(self::categoryItem($owner, $category), 'attribute_not_exists(PK)'),
                    TransactionOperation::put($lockKey + ['categoryId' => $category->id], 'attribute_not_exists(PK)'),
                ]);

                return;
            } catch (TransactionCancelled $cancelled) {
                $last = $cancelled;
                if (!self::conditionFailedAt($cancelled, self::CREATE_LOCK_POSITION)) {
                    throw $cancelled;
                }
                if ($this->settleLockFailure($owner, $lockKey, $category->id, $cancelled, $attempt < self::MAX_CREATE_ATTEMPTS)) {
                    return;
                }
            }
        }

        throw $last ?? new LogicException('The create loop ran no attempt.');
    }

    /**
     * @param array{PK: string, SK: string} $lockKey the lock the write tried to take
     *
     * @return bool true when the lock is held by `$ownId` (our own earlier attempt), false when a stale lock was dealt
     *              with and the write should be tried again
     *
     * @throws CategoryNameTaken
     * @throws TransactionCancelled when the lock is gone or stale and there is no attempt left
     * @throws StorageFailure
     */
    private function settleLockFailure(Principal $owner, array $lockKey, string $ownId, TransactionCancelled $cancelled, bool $mayRetry): bool
    {
        $lock = $this->items->getItem($lockKey);
        if ($lock === null) {
            // Released between the failed write and this read: another try can win it.
            return $mayRetry ? false : throw $cancelled;
        }
        $holder = $lock['categoryId'] ?? null;
        if (is_string($holder) && Uuid::isValid($holder)) {
            if ($holder === $ownId) {
                return true;
            }
            if ($this->items->getItem(CategoryKeys::category($owner, $holder)) !== null) {
                throw new CategoryNameTaken();
            }
        }
        // The holder does not exist, or is not even an id: either way the name would stay unusable for ever.
        if (!$mayRetry) {
            throw $cancelled;
        }
        $this->removeStaleLock($lockKey, $lock);

        return false;
    }

    /**
     * Removes a stale lock conditionally, so a lock that someone else repaired or took in the meantime is left alone:
     * on the exact `categoryId` string it holds, on having none, or on its holder not being a string at all. Nothing is
     * built from the stored value.
     *
     * @param array{PK: string, SK: string} $lockKey
     * @param array<string, mixed> $lock
     *
     * @throws StorageFailure
     */
    private function removeStaleLock(array $lockKey, array $lock): void
    {
        $names = ['#categoryId' => 'categoryId'];
        try {
            if (!array_key_exists('categoryId', $lock)) {
                $this->items->deleteItem($lockKey, 'attribute_not_exists(#categoryId)', $names);
            } elseif (is_string($lock['categoryId'])) {
                $this->items->deleteItem($lockKey, '#categoryId = :stale', $names, [':stale' => $lock['categoryId']]);
            } else {
                // Not a string: a number would not survive the round trip exactly and other types may not marshal back,
                // so condition on the type instead. A live lock always holds a string (a UUID).
                $this->items->deleteItem($lockKey, 'NOT attribute_type(#categoryId, :type)', $names, [':type' => 'S']);
            }
        } catch (ConditionFailed) {
            // Someone else repaired or took it first: the next attempt finds out which.
        }
    }

    /**
     * @throws InvalidArgumentException when the id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function find(Principal $owner, string $categoryId): ?Category
    {
        $item = $this->items->getItem(CategoryKeys::category($owner, $categoryId));

        return $item === null ? null : self::hydrate($owner, $item, 'GetItem');
    }

    /**
     * B6, B12, B13. The renamed category is returned as the transaction (or the update) wrote it: times are whole
     * seconds, so it equals what a read gives back, on the one-item and the transaction path alike.
     *
     * A cancellation is mapped by position: the category (a re-read, then {@see CategoryNotFound}, {@see StaleVersion}
     * or our own earlier attempt), the new lock ({@see CategoryNameTaken}, or a stale lock removed and the rename sent
     * again) or the old lock. An old lock that is missing, or that another category holds, never makes a category
     * stuck: when the category itself is unchanged the rename is sent again without the old-lock delete (so a foreign
     * lock is never touched).
     *
     * @throws CategoryNameTaken
     * @throws CategoryNotFound
     * @throws StaleVersion
     * @throws TransactionCancelled for any other cancellation (the caller maps overload to 503)
     * @throws InvalidArgumentException when an id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function rename(Principal $owner, Category $current, CategoryName $name, int $expectedVersion, DateTimeImmutable $now): Category
    {
        if ($expectedVersion !== $current->version) {
            throw new StaleVersion();
        }
        $renamed = $current->renamed($name, $now);
        if ($renamed->nameKey === $current->nameKey) {
            return $this->renameKeepingLock($owner, $renamed, $expectedVersion);
        }
        $newLockKey = CategoryKeys::nameLockOfStoredKey($owner, $renamed->nameKey);
        $withOldLock = true;
        $last = null;
        for ($attempt = 1; $attempt <= self::MAX_RENAME_ATTEMPTS; ++$attempt) {
            try {
                $this->items->transactWrite($this->renameOperations($owner, $current, $renamed, $expectedVersion, $withOldLock));

                return $renamed;
            } catch (TransactionCancelled $cancelled) {
                $last = $cancelled;
                if (self::conditionFailedAt($cancelled, self::RENAME_CATEGORY_POSITION)) {
                    return $this->settleCategoryFailure($owner, $renamed);
                }
                if (self::conditionFailedAt($cancelled, self::RENAME_NEW_LOCK_POSITION)) {
                    if ($this->settleLockFailure($owner, $newLockKey, $current->id, $cancelled, $attempt < self::MAX_RENAME_ATTEMPTS)) {
                        return $this->settleCategoryFailure($owner, $renamed);
                    }
                } elseif ($withOldLock && self::conditionFailedAt($cancelled, self::RENAME_OLD_LOCK_POSITION)) {
                    $stored = $this->find($owner, $current->id) ?? throw new CategoryNotFound();
                    if ($stored->version !== $current->version || $stored->nameKey !== $current->nameKey) {
                        return self::verdictOnStored($stored, $renamed);
                    }
                    $withOldLock = false;
                } else {
                    throw $cancelled;
                }
            }
        }

        throw $last ?? new LogicException('The rename loop ran no attempt.');
    }

    /**
     * B13: the category's condition failed, so it is re-read before it is reported.
     *
     * @throws CategoryNotFound
     * @throws StaleVersion
     * @throws StorageFailure
     */
    private function settleCategoryFailure(Principal $owner, Category $renamed): Category
    {
        return self::verdictOnStored($this->find($owner, $renamed->id) ?? throw new CategoryNotFound(), $renamed);
    }

    /**
     * A stored item that holds exactly what this rename wrote (the next version, the new name, key and time) is our own
     * earlier attempt, retried by the SDK: success. Anything else is a change by someone else.
     *
     * @throws StaleVersion
     */
    private static function verdictOnStored(Category $stored, Category $renamed): Category
    {
        if (
            $stored->version !== $renamed->version
            || $stored->name->value() !== $renamed->name->value()
            || $stored->nameKey !== $renamed->nameKey
            || self::format($stored->updatedAt) !== self::format($renamed->updatedAt)
        ) {
            throw new StaleVersion();
        }

        return $stored;
    }

    /**
     * Category first, then the new lock, then (unless left out) the old lock, released with the key it was created with.
     *
     * @return list<TransactionOperation>
     */
    private function renameOperations(Principal $owner, Category $current, Category $renamed, int $expectedVersion, bool $withOldLock): array
    {
        $operations = [
            TransactionOperation::update(
                CategoryKeys::category($owner, $current->id),
                'SET #name = :name, #nameKey = :nameKey, #version = :next, #updatedAt = :updatedAt',
                ['#name' => 'name', '#nameKey' => 'nameKey', '#version' => 'version', '#updatedAt' => 'updatedAt'],
                [':name' => $renamed->name->value(), ':nameKey' => $renamed->nameKey, ':next' => $renamed->version, ':updatedAt' => self::format($renamed->updatedAt), ':expected' => $expectedVersion],
                'attribute_exists(PK) AND #version = :expected',
            ),
            TransactionOperation::put(CategoryKeys::nameLockOfStoredKey($owner, $renamed->nameKey) + ['categoryId' => $current->id], 'attribute_not_exists(PK)'),
        ];
        if ($withOldLock) {
            $operations[] = TransactionOperation::delete(
                CategoryKeys::nameLockOfStoredKey($owner, $current->nameKey),
                '#categoryId = :id',
                ['#categoryId' => 'categoryId'],
                [':id' => $current->id],
            );
        }

        return $operations;
    }

    /**
     * A case-only rename has the same folded name, so the same lock: one UpdateItem (a transaction may not touch one
     * item twice).
     *
     * @throws StorageFailure
     */
    private function renameKeepingLock(Principal $owner, Category $renamed, int $expectedVersion): Category
    {
        try {
            $stored = $this->items->updateItem(
                CategoryKeys::category($owner, $renamed->id),
                'SET #name = :name, #version = :next, #updatedAt = :updatedAt',
                ['#name' => 'name', '#version' => 'version', '#updatedAt' => 'updatedAt'],
                [':name' => $renamed->name->value(), ':next' => $renamed->version, ':updatedAt' => self::format($renamed->updatedAt), ':expected' => $expectedVersion],
                'attribute_exists(PK) AND #version = :expected',
            );
        } catch (ConditionFailed) {
            return $this->settleCategoryFailure($owner, $renamed);
        }

        return self::hydrate($owner, $stored, 'UpdateItem');
    }

    /**
     * B14: the category and its lock go together, the lock released with the key stored on the category. A version race
     * (the category changed since `$current` was read) means a re-read and one retry; a second race is a
     * {@see StaleVersion}. A lock that is missing, or that another category holds, is not touched: the category alone
     * is removed, so it can never become undeletable.
     *
     * @throws CategoryNotFound the category is gone
     * @throws StaleVersion the category kept changing
     * @throws TransactionCancelled for a cancellation that is not a failed condition (the caller maps overload to 503)
     * @throws InvalidArgumentException when an id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function delete(Principal $owner, Category $current): void
    {
        $target = $current;
        for ($attempt = 1; $attempt <= self::MAX_DELETE_ATTEMPTS; ++$attempt) {
            try {
                $this->items->transactWrite($this->deleteOperations($owner, $target));

                return;
            } catch (TransactionCancelled $cancelled) {
                if (!in_array(self::CONDITION_CHECK_FAILED, $cancelled->reasons, true)) {
                    throw $cancelled;
                }
            }
            $stored = $this->find($owner, $current->id) ?? throw new CategoryNotFound();
            if ($stored->version === $target->version && $this->deleteWithoutLock($owner, $stored)) {
                return;
            }
            $target = $stored;
        }

        throw new StaleVersion();
    }

    /**
     * @return list<TransactionOperation>
     */
    private function deleteOperations(Principal $owner, Category $target): array
    {
        return [
            TransactionOperation::delete(
                CategoryKeys::category($owner, $target->id),
                'attribute_exists(PK) AND #version = :version',
                ['#version' => 'version'],
                [':version' => $target->version],
            ),
            TransactionOperation::delete(
                CategoryKeys::nameLockOfStoredKey($owner, $target->nameKey),
                '#categoryId = :id',
                ['#categoryId' => 'categoryId'],
                [':id' => $target->id],
            ),
        ];
    }

    /**
     * @return bool false when the version moved on in the meantime
     *
     * @throws StorageFailure
     */
    private function deleteWithoutLock(Principal $owner, Category $stored): bool
    {
        try {
            $this->items->deleteItem(
                CategoryKeys::category($owner, $stored->id),
                'attribute_exists(PK) AND #version = :version',
                ['#version' => 'version'],
                [':version' => $stored->version],
            );
        } catch (ConditionFailed) {
            return false;
        }

        return true;
    }

    /**
     * @return list<Category>
     *
     * @throws InvalidArgumentException when the principal's user id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function listAll(Principal $owner): array
    {
        $categories = [];
        $startKey = null;
        do {
            $page = $this->items->query(
                '#pk = :pk AND begins_with(SK, :prefix)',
                ['#pk' => TableLayout::PK],
                [':pk' => AuthKeys::userPartitionOf($owner), ':prefix' => CategoryKeys::PREFIX],
                exclusiveStartKey: $startKey,
            );
            foreach ($page->items as $item) {
                $categories[] = self::hydrate($owner, $item, ItemStore::QUERY_OPERATION);
            }
            $startKey = $page->lastEvaluatedKey;
        } while ($startKey !== null);

        return $categories;
    }

    /**
     * @return array<string, mixed>
     */
    private static function categoryItem(Principal $owner, Category $category): array
    {
        return CategoryKeys::category($owner, $category->id) + [
            'categoryId' => $category->id,
            'name' => $category->name->value(),
            'nameKey' => $category->nameKey,
            'version' => $category->version,
            'createdAt' => self::format($category->createdAt),
            'updatedAt' => self::format($category->updatedAt),
        ];
    }

    private static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }

    /**
     * @param array<string, mixed> $item
     * @param string $operation the SDK operation that returned the item, for the failure
     *
     * @throws StorageFailure
     */
    private static function hydrate(Principal $owner, array $item, string $operation): Category
    {
        $id = $item['categoryId'] ?? null;
        $name = is_string($item['name'] ?? null) ? CategoryName::parse($item['name']) : null;
        $nameKey = $item['nameKey'] ?? null;
        $version = $item['version'] ?? null;
        $createdAt = self::parseTime($item['createdAt'] ?? null);
        $updatedAt = self::parseTime($item['updatedAt'] ?? null);
        if (
            !is_string($id) || !Uuid::isValid($id)
            || ($item[TableLayout::PK] ?? null) !== AuthKeys::userPartitionOf($owner)
            || ($item[TableLayout::SK] ?? null) !== CategoryKeys::PREFIX . $id
            || !$name instanceof CategoryName || $name->value() !== $item['name']
            || !is_string($nameKey) || preg_match(CategoryKeys::NAME_KEY_PATTERN, $nameKey) !== 1
            || !is_int($version) || $version < 1
            || $createdAt === null || $updatedAt === null
        ) {
            throw self::corrupt($operation);
        }

        return new Category($id, $name, $nameKey, $version, $createdAt, $updatedAt);
    }

    private static function parseTime(mixed $stored): ?DateTimeImmutable
    {
        if (!is_string($stored)) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $stored, new DateTimeZone('UTC'));

        return $time !== false && $time->format(self::DATE_FORMAT) === $stored ? $time : null;
    }

    private static function conditionFailedAt(TransactionCancelled $cancelled, int $position): bool
    {
        return ($cancelled->reasons[$position] ?? null) === self::CONDITION_CHECK_FAILED;
    }

    private static function corrupt(string $operation): StorageFailure
    {
        return new StorageFailure($operation, StorageFailure::CORRUPT_ITEM);
    }
}
