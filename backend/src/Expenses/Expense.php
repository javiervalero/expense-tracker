<?php

declare(strict_types=1);

namespace App\Expenses;

use App\Infrastructure\Id\Uuid;
use App\Infrastructure\Time\StoredTime;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An expense as the repository stores it (B1, B3, B5, B12, B20): id, day, amount, description, the category id as
 * given (a raw id or none; resolving a deleted or foreign category to null happens at read time through
 * CategoryResolver), a version (1 on create, +1 on every update) and the two times. Times are whole seconds in UTC
 * (as stored), so what create and update return equals a later read. Immutable: an update returns a new object.
 */
final readonly class Expense
{
    /**
     * @throws InvalidArgumentException for an id or category id that is not a lower-case UUID, or a version below 1
     */
    public function __construct(
        public string $id,
        public ExpenseDate $date,
        public Amount $amount,
        public Description $description,
        public ?string $categoryId,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        Uuid::require($id, 'expense id');
        if ($categoryId !== null) {
            Uuid::require($categoryId, 'category id');
        }
        if ($version < 1) {
            throw new InvalidArgumentException('An expense version starts at 1.');
        }
    }

    /**
     * @throws InvalidArgumentException for an id or category id that is not a lower-case UUID
     */
    public static function new(string $id, ExpenseDate $date, Amount $amount, Description $description, ?string $categoryId, DateTimeImmutable $now): self
    {
        $stored = StoredTime::wholeSecondsUtc($now);

        return new self($id, $date, $amount, $description, $categoryId, 1, $stored, $stored);
    }

    /**
     * @throws InvalidArgumentException for a category id that is not a lower-case UUID
     */
    public function updated(ExpenseDate $date, Amount $amount, Description $description, ?string $categoryId, DateTimeImmutable $now): self
    {
        return new self($this->id, $date, $amount, $description, $categoryId, $this->version + 1, $this->createdAt, StoredTime::wholeSecondsUtc($now));
    }
}
