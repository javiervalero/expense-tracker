<?php

declare(strict_types=1);

namespace App\Expenses\Listing;

use App\Categories\CategoryResolver;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Id\Uuid;
use InvalidArgumentException;

/**
 * The `category` filter (B17): one of the caller's category ids, or `none` (Uncategorized, leftover ids of deleted
 * categories included). Only the exact spellings exist: `none` in lower case, or a lower-case canonical UUID.
 */
final readonly class CategoryFilter
{
    private const string NONE = 'none';

    /** @param string|null $id null means `none` */
    private function __construct(public ?string $id) {}

    /** @return self|null null when the value is neither `none` nor a lower-case UUID */
    public static function parse(string $value): ?self
    {
        return match (true) {
            $value === self::NONE => new self(null),
            Uuid::isValid($value) => new self($value),
            default => null,
        };
    }

    /** The spelling the query and the filter hash use. */
    public function value(): string
    {
        return $this->id ?? self::NONE;
    }

    /**
     * @param string|null $storedCategoryId the id as the expense stores it (it may be a leftover)
     *
     * @throws InvalidArgumentException when the resolver's principal id is not a lower-case UUID
     * @throws StorageFailure from the resolver; it ends the request, so it is never caught per item
     */
    public function matches(?string $storedCategoryId, CategoryResolver $categories): bool
    {
        return $this->id === null
            ? $categories->resolve($storedCategoryId) === null
            : $storedCategoryId === $this->id;
    }
}
