<?php

declare(strict_types=1);

namespace App\Auth\Http;

use App\Auth\Email;
use App\Auth\InvalidEmail;
use App\Auth\Password\PasswordPolicy;
use App\Auth\Password\PasswordViolation;

/**
 * The texts of the field errors of the credential endpoints (register and login), in one place so the two cannot
 * drift. Exhaustive `match` arms with no `default`: a new reason cannot be swallowed. Built from the constants of the
 * rules; a text never repeats a value.
 */
final class FieldMessages
{
    public static function email(InvalidEmail $problem): string
    {
        return match ($problem) {
            InvalidEmail::Empty => 'Enter an email address.',
            InvalidEmail::TooLong => 'The email address must have at most ' . Email::MAX_BYTES . ' bytes.',
            InvalidEmail::ControlCharacter => 'The email address must not contain control characters.',
            InvalidEmail::NonAscii => 'The email address must use ASCII characters only.',
            InvalidEmail::Malformed => 'Enter a valid email address.',
        };
    }

    public static function password(PasswordViolation $problem): string
    {
        return match ($problem) {
            PasswordViolation::InvalidEncoding => 'The password must be valid UTF-8.',
            PasswordViolation::TooShort => 'The password must have at least ' . PasswordPolicy::MIN_CHARS . ' characters.',
            PasswordViolation::TooLong => 'The password must have at most ' . PasswordPolicy::MAX_CHARS . ' characters.',
            PasswordViolation::ControlCharacter => 'The password must not contain control characters.',
        };
    }
}
