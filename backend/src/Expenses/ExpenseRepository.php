<?php

declare(strict_types=1);

namespace App\Expenses;

use App\Auth\AuthKeys;
use App\Auth\Session\Principal;
use App\Expenses\Listing\ExpenseRangeReader;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StaleVersion;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\DynamoDb\TransactionOperation;
use App\Infrastructure\Id\Uuid;
use App\Infrastructure\Time\StoredTime;
use InvalidArgumentException;

/**
 * Expenses in the user's partition. Items are built and read field by field: `PK`, `SK` and `LSI1SK` stay inside this
 * class. A missing or wrongly typed attribute is a {@see StorageFailure} (`CorruptItem`).
 */
final readonly class ExpenseRepository implements ExpenseRangeReader
{
    /** The date part of an index key; the key as a whole is checked by rebuilding it from the date and the id. */
    private const string INDEX_KEY_DATE = '/\AEXP#(\d{4}-\d{2}-\d{2})#/D';
    private const string CONDITION_CHECK_FAILED = 'ConditionalCheckFailed';
    /** Position of the expense in a delete's transaction (expense first, receipt record second). */
    private const int DELETE_EXPENSE_POSITION = 0;

    public function __construct(private ItemStore $items) {}

    /**
     * B13: a failed `attribute_not_exists(PK)` is re-read before it is reported. A stored item equal to ours is our own
     * earlier attempt (the SDK retried a write that went through): success.
     *
     * @throws ConditionFailed another expense holds the id
     * @throws InvalidArgumentException when an id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function create(Principal $owner, Expense $expense): void
    {
        try {
            $this->items->putItem(self::expenseItem($owner, $expense), 'attribute_not_exists(PK)');
        } catch (ConditionFailed $failed) {
            $stored = $this->find($owner, $expense->id);
            if ($stored === null || !self::sameContent($stored, $expense)) {
                throw $failed;
            }
        }
    }

    /**
     * @throws InvalidArgumentException when the id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function find(Principal $owner, string $expenseId): ?Expense
    {
        $item = $this->items->getItem(ExpenseKeys::expense($owner, $expenseId));

        return $item === null ? null : self::hydrate($owner, $item, 'GetItem');
    }

    /**
     * B12, B13: `$updated` is the next version of the stored expense (`$current->updated(...)`). `date` and `LSI1SK` are
     * set together, so the item moves in the index and keeps its id. A failed condition is re-read before it is
     * reported: a missing item is {@see ExpenseNotFound}; an item at the next version that holds exactly what this
     * update wrote (fields and `updatedAt`) is our own earlier attempt, retried by the SDK, and is returned as
     * success; anything else is a {@see StaleVersion}.
     *
     * @throws ExpenseNotFound
     * @throws StaleVersion
     * @throws InvalidArgumentException when an id is not a lower-case UUID, or `$updated` is not version `$expectedVersion + 1`
     * @throws StorageFailure
     */
    public function update(Principal $owner, Expense $updated, int $expectedVersion): Expense
    {
        if ($updated->version !== $expectedVersion + 1) {
            throw new InvalidArgumentException('An updated expense must be the version after the expected one.');
        }
        try {
            $stored = $this->items->updateItem(
                ExpenseKeys::expense($owner, $updated->id),
                ...self::updateParts($updated, $expectedVersion),
            );
        } catch (ConditionFailed) {
            $stored = $this->find($owner, $updated->id) ?? throw new ExpenseNotFound();

            return self::wroteSameFields($stored, $updated) ? $stored : throw new StaleVersion();
        }

        return self::hydrate($owner, $stored, 'UpdateItem');
    }

    /**
     * Two literal update expressions, chosen by whether the expense has a category: a removed category must leave no
     * attribute behind, and DynamoDB refuses names and values that an expression does not use.
     *
     * @return array{literal-string, array<string, literal-string>, array<string, mixed>, literal-string}
     */
    private static function updateParts(Expense $updated, int $expectedVersion): array
    {
        $names = [
            '#date' => 'date',
            '#lsi1sk' => TableLayout::LSI1SK,
            '#amountCents' => 'amountCents',
            '#description' => 'description',
            '#categoryId' => 'categoryId',
            '#version' => 'version',
            '#updatedAt' => 'updatedAt',
        ];
        $values = [
            ':date' => $updated->date->value(),
            ':lsi1sk' => ExpenseKeys::indexKey($updated->date, $updated->id),
            ':amountCents' => $updated->amount->cents(),
            ':description' => $updated->description->value(),
            ':next' => $updated->version,
            ':updatedAt' => StoredTime::format($updated->updatedAt),
            ':expected' => $expectedVersion,
        ];
        $condition = 'attribute_exists(PK) AND #version = :expected';

        return match ($updated->categoryId) {
            null => [
                'SET #date = :date, #lsi1sk = :lsi1sk, #amountCents = :amountCents, #description = :description, #version = :next, #updatedAt = :updatedAt REMOVE #categoryId',
                $names,
                $values,
                $condition,
            ],
            default => [
                'SET #date = :date, #lsi1sk = :lsi1sk, #amountCents = :amountCents, #description = :description, #categoryId = :categoryId, #version = :next, #updatedAt = :updatedAt',
                $names,
                $values + [':categoryId' => $updated->categoryId],
                $condition,
            ],
        };
    }

    /**
     * B15, ADR 0002: one transaction deletes `EXP#<id>` (it must exist) and, unconditionally, `RCPT#<id>`, which is
     * a no-op until receipts exist. Deleting is the user's explicit intent, so there is no version condition. A failed
     * condition on the expense (position 0) is {@see ExpenseNotFound}; any other cancellation (throttling, a
     * transaction conflict) is rethrown unchanged, so the caller can map overload to 503. One attempt, no loop.
     *
     * @throws ExpenseNotFound
     * @throws TransactionCancelled for a cancellation that is not a failed expense condition
     * @throws InvalidArgumentException when the id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function delete(Principal $owner, string $expenseId): void
    {
        $operations = [
            TransactionOperation::delete(ExpenseKeys::expense($owner, $expenseId), 'attribute_exists(PK)'),
            TransactionOperation::delete(ExpenseKeys::receipt($owner, $expenseId)),
        ];
        try {
            $this->items->transactWrite($operations);
        } catch (TransactionCancelled $cancelled) {
            throw ($cancelled->reasons[self::DELETE_EXPENSE_POSITION] ?? null) === self::CONDITION_CHECK_FAILED
                ? new ExpenseNotFound()
                : $cancelled;
        }
    }

    /**
     * B18, R1: one batch of the user's expenses on `LSI1`, newest day first, both end days included, strongly
     * consistent. `$startKey` is the previous batch's `lastKey`. DynamoDB resumes from that position even when its item
     * was deleted or re-dated in between (nothing skipped or repeated; a re-dated item shows at its new place); R1's
     * tests pin it. The partition is the principal's, never part of the key, so a start key can only move within the
     * caller's own data, and it must lie inside `[from, to]`. The caller applies its own page and budget limits and any
     * filter; this method returns what the query evaluated, at most `$batchLimit` items.
     *
     * `lastKey` is a valid cursor only if the caller examined the whole batch. A caller that stops in the middle of a
     * batch must build its cursor from the item it stopped at (B18, WP7), not use `lastKey`.
     *
     * @param array<string, string>|null $startKey `SK` and `LSI1SK` of an expense; anything else is refused
     *
     * @throws InvalidStartKey when the start key is not an expense position inside the range (nothing is sent)
     * @throws InvalidArgumentException when `$from` is after `$to`, or the limit is outside 1 to {@see ItemStore::MAX_QUERY_LIMIT}
     * @throws StorageFailure
     */
    public function pageByDate(Principal $owner, ExpenseDate $from, ExpenseDate $to, ?array $startKey, int $batchLimit): ExpenseBatch
    {
        if ($from->isAfter($to)) {
            throw new InvalidArgumentException('The range starts after it ends.');
        }
        $partition = AuthKeys::userPartitionOf($owner);
        $page = $this->items->query(
            '#pk = :pk AND LSI1SK BETWEEN :from AND :to',
            ['#pk' => TableLayout::PK],
            [':pk' => $partition, ':from' => ExpenseKeys::rangeStart($from), ':to' => ExpenseKeys::rangeEnd($to)],
            TableLayout::LSI1,
            scanForward: false,
            limit: $batchLimit,
            exclusiveStartKey: $startKey === null ? null : [TableLayout::PK => $partition] + self::validStartKey($startKey, $from, $to),
        );
        $expenses = [];
        foreach ($page->items as $item) {
            $expenses[] = self::hydrate($owner, $item, ItemStore::QUERY_OPERATION);
        }

        return new ExpenseBatch($expenses, self::lastKeyOf($page->lastEvaluatedKey, $partition, $from, $to));
    }

    /**
     * @param array<string, mixed> $startKey
     *
     * @return array{SK: string, LSI1SK: string}
     *
     * @throws InvalidStartKey for a bad shape, sort key or index key, or an index key outside `[from, to]`
     */
    private static function validStartKey(array $startKey, ExpenseDate $from, ExpenseDate $to): array
    {
        $position = self::expensePosition($startKey);
        if ($position[TableLayout::LSI1SK] < ExpenseKeys::rangeStart($from) || $position[TableLayout::LSI1SK] > ExpenseKeys::rangeEnd($to)) {
            throw new InvalidStartKey();
        }

        return $position;
    }

    /**
     * Exactly an `SK` and an `LSI1SK`, as strings: `EXP#<uuid>` and the index key rebuilt from its own date and that id.
     *
     * @param array<string, mixed> $key
     *
     * @return array{SK: string, LSI1SK: string}
     *
     * @throws InvalidStartKey
     */
    private static function expensePosition(array $key): array
    {
        $sortKey = $key[TableLayout::SK] ?? null;
        $indexKey = $key[TableLayout::LSI1SK] ?? null;
        if (
            count($key) !== 2 || !is_string($sortKey) || !is_string($indexKey)
            || !str_starts_with($sortKey, ExpenseKeys::PREFIX)
            || preg_match(self::INDEX_KEY_DATE, $indexKey, $parts) !== 1
        ) {
            throw new InvalidStartKey();
        }
        $date = ExpenseDate::parse($parts[1]);
        try {
            $rebuilt = $date === null ? null : ExpenseKeys::indexKey($date, substr($sortKey, strlen(ExpenseKeys::PREFIX)));
        } catch (InvalidArgumentException) {
            throw new InvalidStartKey();
        }
        if ($rebuilt !== $indexKey) {
            throw new InvalidStartKey();
        }

        return [TableLayout::SK => $sortKey, TableLayout::LSI1SK => $indexKey];
    }

    /**
     * What DynamoDB handed back is checked like a start key a caller sends: a cursor is never built from anything else.
     *
     * @param array<string, mixed>|null $lastEvaluatedKey
     *
     * @return array{SK: string, LSI1SK: string}|null
     *
     * @throws StorageFailure
     */
    private static function lastKeyOf(?array $lastEvaluatedKey, string $partition, ExpenseDate $from, ExpenseDate $to): ?array
    {
        if ($lastEvaluatedKey === null) {
            return null;
        }
        $position = [TableLayout::SK => $lastEvaluatedKey[TableLayout::SK] ?? null, TableLayout::LSI1SK => $lastEvaluatedKey[TableLayout::LSI1SK] ?? null];
        try {
            if (($lastEvaluatedKey[TableLayout::PK] ?? null) !== $partition || count($lastEvaluatedKey) !== 3) {
                throw new InvalidStartKey();
            }

            return self::validStartKey($position, $from, $to);
        } catch (InvalidStartKey) {
            throw self::corrupt(ItemStore::QUERY_OPERATION);
        }
    }

    /**
     * The B13 replay check of a create: everything we wrote, times to the second.
     */
    private static function sameContent(Expense $stored, Expense $ours): bool
    {
        return $stored->id === $ours->id
            && StoredTime::format($stored->createdAt) === StoredTime::format($ours->createdAt)
            && self::wroteSameFields($stored, $ours);
    }

    /**
     * The B13 replay check of an update: the version and exactly the fields and `updatedAt` it wrote.
     */
    private static function wroteSameFields(Expense $stored, Expense $ours): bool
    {
        return $stored->version === $ours->version
            && $stored->date->value() === $ours->date->value()
            && $stored->amount->cents() === $ours->amount->cents()
            && $stored->description->value() === $ours->description->value()
            && $stored->categoryId === $ours->categoryId
            && StoredTime::format($stored->updatedAt) === StoredTime::format($ours->updatedAt);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when an id is not a lower-case UUID
     */
    private static function expenseItem(Principal $owner, Expense $expense): array
    {
        $item = ExpenseKeys::expense($owner, $expense->id) + [
            TableLayout::LSI1SK => ExpenseKeys::indexKey($expense->date, $expense->id),
            'expenseId' => $expense->id,
            'date' => $expense->date->value(),
            'amountCents' => $expense->amount->cents(),
            'description' => $expense->description->value(),
            'version' => $expense->version,
            'createdAt' => StoredTime::format($expense->createdAt),
            'updatedAt' => StoredTime::format($expense->updatedAt),
        ];
        if ($expense->categoryId !== null) {
            $item['categoryId'] = $expense->categoryId;
        }

        return $item;
    }

    /**
     * Full validation of a stored item: a read never guesses. The keys must belong to this owner and this id, the index
     * key must agree with the date and id, and every value must have the type and range this code wrote.
     *
     * @param array<string, mixed> $item
     * @param string $operation the SDK operation that returned the item, for the failure
     *
     * @throws StorageFailure
     */
    private static function hydrate(Principal $owner, array $item, string $operation): Expense
    {
        $id = $item['expenseId'] ?? null;
        $date = is_string($item['date'] ?? null) ? ExpenseDate::parse($item['date']) : null;
        $amount = Amount::parse($item['amountCents'] ?? null);
        $description = is_string($item['description'] ?? null) ? Description::parse($item['description']) : null;
        $categoryId = $item['categoryId'] ?? null;
        $categoryIdIsValid = !array_key_exists('categoryId', $item) || (is_string($categoryId) && Uuid::isValid($categoryId));
        $version = $item['version'] ?? null;
        $createdAt = StoredTime::parse($item['createdAt'] ?? null);
        $updatedAt = StoredTime::parse($item['updatedAt'] ?? null);
        if (
            !is_string($id) || !Uuid::isValid($id) || $date === null
            || ($item[TableLayout::PK] ?? null) !== AuthKeys::userPartitionOf($owner)
            || ($item[TableLayout::SK] ?? null) !== ExpenseKeys::PREFIX . $id
            || ($item[TableLayout::LSI1SK] ?? null) !== ExpenseKeys::indexKey($date, $id)
            || !$amount instanceof Amount
            || !$description instanceof Description || $description->value() !== $item['description']
            || !$categoryIdIsValid
            || !is_int($version) || $version < 1
            || $createdAt === null || $updatedAt === null
        ) {
            throw self::corrupt($operation);
        }

        return new Expense($id, $date, $amount, $description, is_string($categoryId) ? $categoryId : null, $version, $createdAt, $updatedAt);
    }

    private static function corrupt(string $operation): StorageFailure
    {
        return new StorageFailure($operation, StorageFailure::CORRUPT_ITEM);
    }
}
