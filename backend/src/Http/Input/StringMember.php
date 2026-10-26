<?php

declare(strict_types=1);

namespace App\Http\Input;

use App\Http\Problem\FieldError;

/**
 * A member of a decoded JSON object that must be a string, shared by every body shape. The error sits at the pointer
 * of the member and its message never repeats a value. `''` is a string and is returned: whether it is acceptable is
 * for the caller's value object to say.
 */
final class StringMember
{
    private const string REQUIRED = 'This field is required.';
    private const string MUST_BE_STRING = 'Must be a string.';

    private function __construct() {}

    /**
     * The member must be present and a string.
     *
     * @param array<array-key, mixed> $body
     */
    public static function required(#[\SensitiveParameter] array $body, string $member): string|FieldError
    {
        if (!array_key_exists($member, $body)) {
            return new FieldError('/' . $member, self::REQUIRED);
        }

        return is_string($body[$member]) ? $body[$member] : new FieldError('/' . $member, self::MUST_BE_STRING);
    }

    /**
     * The member may be absent (`null`); when present it must be a string, so a JSON `null` is an error.
     *
     * @param array<array-key, mixed> $body
     */
    public static function optional(#[\SensitiveParameter] array $body, string $member): string|FieldError|null
    {
        return array_key_exists($member, $body) ? self::required($body, $member) : null;
    }
}
