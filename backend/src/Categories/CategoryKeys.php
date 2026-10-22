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
 * `USER#<userId>` / `CATNAME#<name key>` for its name lock. The partition comes from the session principal, never from the
 * request; ids are validated again here, so a key is never built from free text. `CATNAME#` does not start with
 * `CAT#`, so `begins_with(SK, 'CAT#')` never returns a lock.
 */
final class CategoryKeys
{
    public const string PREFIX = 'CAT#';
    public const string NAME_LOCK_PREFIX = 'CATNAME#';

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
     * @return array{PK: string, SK: string}
     *
     * @throws InvalidArgumentException when the principal's user id is not a lower-case canonical UUID
     */
    public static function nameLock(Principal $owner, CategoryName $name): array
    {
        return [
            TableLayout::PK => AuthKeys::userPartitionOf($owner),
            TableLayout::SK => self::NAME_LOCK_PREFIX . $name->key(),
        ];
    }
}
