<?php

declare(strict_types=1);

namespace App\Expenses;

/**
 * An expense amount in cents (B1): a JSON integer from 1 to {@see self::MAX_CENTS}. A float is refused, `12.0`
 * included (PHP decodes it as a float), and so are strings, booleans, null and lists. An integer beyond 64 bits
 * arrives as a string (`JSON_BIGINT_AS_STRING`), so it is refused too. The bound keeps any monthly sum far below
 * JavaScript's 2^53.
 */
final class Amount
{
    public const int MIN_CENTS = 1;
    public const int MAX_CENTS = 100_000_000;

    private function __construct(private readonly int $cents) {}

    public static function parse(mixed $input): self|AmountViolation
    {
        return match (true) {
            !is_int($input) => AmountViolation::NotAnInteger,
            $input < self::MIN_CENTS => AmountViolation::TooSmall,
            $input > self::MAX_CENTS => AmountViolation::TooLarge,
            default => new self($input),
        };
    }

    public function cents(): int
    {
        return $this->cents;
    }
}
