<?php

declare(strict_types=1);

namespace App\Expenses;

use App\Auth\AuthKeys;
use App\Auth\Session\Principal;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\Id\Uuid;
use InvalidArgumentException;

/**
 * The keys of the expense items (ADR 0002, B9, B15, B18): `USER#<userId>` / `EXP#<expenseId>` for an expense, the
 * `LSI1` sort key `EXP#<yyyy-mm-dd>#<expenseId>` that orders a user's expenses by day, and `RCPT#<expenseId>` for the
 * receipt record reserved for Phase 5. The partition comes from the session principal, never from the request; ids are
 * validated again here.
 */
final class ExpenseKeys
{
    public const string PREFIX = 'EXP#';
    public const string RECEIPT_PREFIX = 'RCPT#';

    /** Sorts after every character of a date or an id (`~` is U+007E), so `EXP#<day>~` ends the range of that day. */
    private const string RANGE_END_SUFFIX = '~';

    /**
     * @return array{PK: string, SK: string}
     *
     * @throws InvalidArgumentException when an id is not a lower-case canonical UUID
     */
    public static function expense(Principal $owner, string $expenseId): array
    {
        return [
            TableLayout::PK => AuthKeys::userPartitionOf($owner),
            TableLayout::SK => self::PREFIX . Uuid::require($expenseId, 'expense id'),
        ];
    }

    /**
     * @return array{PK: string, SK: string}
     *
     * @throws InvalidArgumentException when an id is not a lower-case canonical UUID
     */
    public static function receipt(Principal $owner, string $expenseId): array
    {
        return [
            TableLayout::PK => AuthKeys::userPartitionOf($owner),
            TableLayout::SK => self::RECEIPT_PREFIX . Uuid::require($expenseId, 'expense id'),
        ];
    }

    /**
     * @throws InvalidArgumentException when the id is not a lower-case canonical UUID
     */
    public static function indexKey(ExpenseDate $date, string $expenseId): string
    {
        return self::PREFIX . $date->value() . '#' . Uuid::require($expenseId, 'expense id');
    }

    /** The first index key of the day (inclusive lower bound of a `BETWEEN`). */
    public static function rangeStart(ExpenseDate $from): string
    {
        return self::PREFIX . $from->value();
    }

    /** After every index key of the day (inclusive upper bound of a `BETWEEN`). */
    public static function rangeEnd(ExpenseDate $to): string
    {
        return self::PREFIX . $to->value() . self::RANGE_END_SUFFIX;
    }
}
