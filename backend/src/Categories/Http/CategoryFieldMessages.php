<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Categories\CategoryName;
use App\Http\Input\TextViolation;

/**
 * The texts of the field errors of the category endpoints. Exhaustive `match` arms with no `default`: a new reason
 * cannot be swallowed. Built from the constants of the rules; a text never repeats a value.
 */
final class CategoryFieldMessages
{
    public static function name(TextViolation $problem): string
    {
        return match ($problem) {
            TextViolation::InvalidEncoding => 'The name must be valid UTF-8.',
            TextViolation::Empty => 'Enter a name.',
            TextViolation::TooLong => 'The name must have at most ' . CategoryName::MAX_CHARS . ' characters.',
            TextViolation::ControlCharacter => 'The name must not contain control characters.',
            TextViolation::BidiControl => 'The name must not contain text-direction control characters.',
            TextViolation::InvisibleCharacter => 'The name must not contain invisible characters.',
        };
    }
}
