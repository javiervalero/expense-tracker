<?php

declare(strict_types=1);

namespace App\Categories;

use App\Auth\AuthKeys;
use App\Auth\Session\Principal;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\Id\Uuid;
use InvalidArgumentException;

/**
 * The keys of the category items (ADR 0002, B6, B9): `USER#<userId>` / `CAT#<categoryId>` for a category and
 * `USER#<userId>` / `CATNAME#<name key>` for its name lock. A lock is addressed only by a stored name key. The partition comes from the session principal, never from the
 * request; ids are validated again here, so a key is never built from free text. `CATNAME#` does not start with
 * `CAT#`, so `begins_with(SK, 'CAT#')` never returns a lock.
 */
final class CategoryKeys
{
    public const string PREFIX = 'CAT#';
    public const string NAME_LOCK_PREFIX = 'CATNAME#';
    /** The one pattern of a stored name key (SHA-256 hex): Category, the key builder and the repository all use it. */
    public const string NAME_KEY_PATTERN = '/\A[0-9a-f]{64}\z/D';

    /**
     * @return array{PK: string, SK: string}
     *
     * @throws InvalidArgumentException when an id is not a lower-case canonical UUID
     */
    public static function category(Principal $owner, string $categoryId): array
    {
        return [
            TableLayout::PK => AuthKeys::userPartitionOf($owner),
            TableLayout::SK => self::PREFIX . Uuid::require($categoryId, 'category id'),
        ];
    }

    /**
     * The name lock of a category, from the `nameKey` stored on its `CAT#` item (B6, WP3 note): a lock is released or
     * checked with the key it was created with, never with a recomputed one, so a change in PHP's case-folding tables
     * cannot orphan it.
     *
     * @return array{PK: string, SK: string}
     *
     * @throws InvalidArgumentException when the key is not 64 lower-case hex characters, or the principal's user id is not a UUID
     */
    public static function nameLockOfStoredKey(Principal $owner, string $storedNameKey): array
    {
        if (preg_match(self::NAME_KEY_PATTERN, $storedNameKey) !== 1) {
            throw new InvalidArgumentException('A category name key is 64 lower-case hex characters.');
        }

        return [
            TableLayout::PK => AuthKeys::userPartitionOf($owner),
            TableLayout::SK => self::NAME_LOCK_PREFIX . $storedNameKey,
        ];
    }
}
