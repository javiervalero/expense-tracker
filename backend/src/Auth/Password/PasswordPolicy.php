<?php

declare(strict_types=1);

namespace App\Auth\Password;

/**
 * The password rules (A11): 12 to 128 characters (`mb_strlen`), valid UTF-8, no control characters. Spaces and
 * Unicode are allowed; there are no composition rules.
 * Passwords are compared byte for byte: there is no Unicode normalisation, because the `intl` extension is in neither
 * the tools image nor the Bref image.
 *
 * Registration uses {@see self::violation()}. Login uses only {@see self::exceedsLoginLimit()}, so a later policy
 * change never locks anyone out.
 */
final class PasswordPolicy
{
    public const MIN_CHARS = 12;
    public const MAX_CHARS = 128;

    /**
     * The first rule broken, or null when the password is acceptable.
     */
    public function violation(#[\SensitiveParameter] string $password): ?PasswordViolation
    {
        if (!mb_check_encoding($password, 'UTF-8')) {
            return PasswordViolation::InvalidEncoding;
        }
        $length = mb_strlen($password, 'UTF-8');

        return match (true) {
            $length < self::MIN_CHARS => PasswordViolation::TooShort,
            $length > self::MAX_CHARS => PasswordViolation::TooLong,
            preg_match('/\p{Cc}/u', $password) === 1 => PasswordViolation::ControlCharacter,
            default => null,
        };
    }

    public function exceedsLoginLimit(#[\SensitiveParameter] string $password): bool
    {
        // Invalid UTF-8 has no character count: measure bytes, so junk is bounded and never throws.
        $length = mb_check_encoding($password, 'UTF-8') ? mb_strlen($password, 'UTF-8') : strlen($password);

        return $length > self::MAX_CHARS;
    }
}
