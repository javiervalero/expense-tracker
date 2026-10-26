<?php

declare(strict_types=1);

namespace App\Http\Input;

use App\Http\Problem\FieldError;

/**
 * The `version` member of a body that edits an existing item (optimistic locking), shared by every such body shape:
 * required, a JSON integer of at least 1. `12.0` decodes as a float and is refused, and so is an integer beyond 64 bits
 * (which {@see \App\Http\JsonBody} keeps as a string). The error sits at `/version` and never repeats a value.
 */
final class VersionMember
{
    private const string MEMBER = 'version';
    private const string POINTER = '/version';
    private const string REQUIRED = 'This field is required.';
    private const string MUST_BE_INTEGER = 'Must be an integer.';
    private const string MUST_BE_AT_LEAST_ONE = 'Must be at least 1.';
    private const int MIN_VERSION = 1;

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $body
     */
    public static function required(#[\SensitiveParameter] array $body): int|FieldError
    {
        if (!array_key_exists(self::MEMBER, $body)) {
            return new FieldError(self::POINTER, self::REQUIRED);
        }
        $value = $body[self::MEMBER];
        if (!is_int($value)) {
            return new FieldError(self::POINTER, self::MUST_BE_INTEGER);
        }

        return $value < self::MIN_VERSION ? new FieldError(self::POINTER, self::MUST_BE_AT_LEAST_ONE) : $value;
    }
}
