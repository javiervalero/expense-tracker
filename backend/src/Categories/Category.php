<?php

declare(strict_types=1);

namespace App\Categories;

use App\Infrastructure\Id\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A category as the repository stores it (B6, B12, B20): id, name, the `nameKey` of the name lock it holds, a
 * version (1 on create, +1 on every update) and the two times. `nameKey` is the key the lock was created with
 * (set from the name when the category is made or renamed, and read back from storage as stored), so a lock is
 * always released with the key it was created with. Times are whole seconds in UTC (as stored). Immutable: a rename returns a new object.
 */
final readonly class Category
{
    /**
     * @throws InvalidArgumentException for an id that is not a lower-case UUID, a name key that is not 64 lower-case
     *                                  hex characters, or a version below 1
     */
    public function __construct(
        public string $id,
        public CategoryName $name,
        public string $nameKey,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        Uuid::require($id, 'category id');
        if (preg_match(CategoryKeys::NAME_KEY_PATTERN, $nameKey) !== 1) {
            throw new InvalidArgumentException('A category name key is 64 lower-case hex characters.');
        }
        if ($version < 1) {
            throw new InvalidArgumentException('A category version starts at 1.');
        }
    }

    public static function new(string $id, CategoryName $name, DateTimeImmutable $now): self
    {
        $stored = self::wholeSecondsUtc($now);

        return new self($id, $name, $name->key(), 1, $stored, $stored);
    }

    public function renamed(CategoryName $name, DateTimeImmutable $now): self
    {
        return new self($this->id, $name, $name->key(), $this->version + 1, $this->createdAt, self::wholeSecondsUtc($now));
    }

    /** Stored times have whole seconds in UTC, so a new or renamed category equals what a read gives back. */
    private static function wholeSecondsUtc(DateTimeImmutable $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'), new DateTimeZone('UTC'));
    }
}
