<?php

declare(strict_types=1);

namespace App\Expenses;

/**
 * The calendar day of an expense (B3): exactly `YYYY-MM-DD` with ASCII digits and a real day, from {@see self::MIN}
 * to {@see self::MAX}. Future dates are allowed: the server does not know the user's time zone. The date has no
 * time and no zone, and its text sorts like the day, which is what the `LSI1` sort key relies on.
 */
final class ExpenseDate
{
    public const string MIN = '2000-01-01';
    public const string MAX = '2099-12-31';
    private const string FORMAT = '/\A(\d{4})-(\d{2})-(\d{2})\z/D';

    private function __construct(private readonly string $value) {}

    public static function parse(string $input): ?self
    {
        if (preg_match(self::FORMAT, $input, $parts) !== 1) {
            return null;
        }
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $input < self::MIN || $input > self::MAX ? null : new self($input);
    }

    public static function min(): self
    {
        return new self(self::MIN);
    }

    public static function max(): self
    {
        return new self(self::MAX);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }
}
