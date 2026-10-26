<?php

declare(strict_types=1);

namespace App\Expenses\Http;

use App\Expenses\Amount;
use App\Expenses\AmountViolation;
use App\Expenses\Description;
use App\Expenses\ExpenseDate;
use App\Http\Input\TextViolation;

/**
 * The texts of the field errors of the expense endpoints, in one place. Exhaustive `match` arms with no `default`:
 * a new reason cannot be swallowed. Built from the constants of the rules; a text never repeats a value.
 */
final class ExpenseFieldMessages
{
    public static function amount(AmountViolation $problem): string
    {
        return match ($problem) {
            AmountViolation::NotAnInteger => 'Must be a whole number of cents.',
            AmountViolation::TooSmall => 'Must be at least ' . Amount::MIN_CENTS . ' cent.',
            AmountViolation::TooLarge => 'Must be at most ' . Amount::MAX_CENTS . ' cents.',
        };
    }

    public static function description(TextViolation $problem): string
    {
        return match ($problem) {
            TextViolation::InvalidEncoding => 'The description must be valid UTF-8.',
            TextViolation::Empty => 'Enter a description.',
            TextViolation::TooLong => 'The description must have at most ' . Description::MAX_CHARS . ' characters.',
            TextViolation::ControlCharacter => 'The description must not contain control characters.',
            TextViolation::BidiControl => 'The description must not contain text-direction control characters.',
            TextViolation::InvisibleCharacter => 'The description must not contain invisible characters.',
        };
    }

    public static function date(): string
    {
        return 'Must be a valid date in the form YYYY-MM-DD, from ' . ExpenseDate::MIN . ' to ' . ExpenseDate::MAX . '.';
    }
}
