<?php

declare(strict_types=1);

namespace App\Http\Input;

use App\Http\Problem\FieldError;

/**
 * The unknown-member errors of a decoded JSON object, shared by every body shape (extracted from
 * `CredentialsInput`). A pointer names the member but a message never repeats a value or a key. A key is echoed in
 * its pointer only when it is short and plain (letters, digits, `_`, `.` and `-`); any other (long, or with control
 * characters) is reported for the whole body, so a pointer is always safe to log and to show.
 */
final class ObjectMembers
{
    /** Unknown keys beyond this many are dropped from the errors, so a huge body cannot make a huge response. */
    public const int MAX_UNKNOWN_KEY_ERRORS = 5;
    private const string SAFE_KEY = '/\A[A-Za-z0-9_.-]{1,64}\z/D';

    /**
     * @param array<array-key, mixed> $body
     * @param list<string>            $allowed
     *
     * @return list<FieldError>
     */
    public static function unknownErrors(#[\SensitiveParameter] array $body, array $allowed): array
    {
        $errors = [];
        foreach (array_keys($body) as $key) {
            $key = (string) $key;
            if (in_array($key, $allowed, true)) {
                continue;
            }
            if (count($errors) >= self::MAX_UNKNOWN_KEY_ERRORS) {
                break;
            }
            $errors[] = new FieldError(self::pointerTo($key), 'This field is not allowed.');
        }

        return $errors;
    }

    private static function pointerTo(string $key): string
    {
        if (preg_match(self::SAFE_KEY, $key) !== 1) {
            return '';
        }

        // The safe set holds neither "/" nor "~", so no JSON Pointer escaping is needed.
        return '/' . $key;
    }
}
