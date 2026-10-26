<?php

declare(strict_types=1);

namespace App\Http\Input;

use App\Http\Problem\FieldError;

/**
 * The `categoryId` member of an expense body: a string, or `null` for no category. Only its JSON type is checked here;
 * whether the string is one of the caller's categories needs a read, so the handler decides that. An absent member and
 * a JSON `null` both return `null`: a caller that must tell them apart checks `array_key_exists` itself.
 */
final class CategoryIdMember
{
    private const string MEMBER = 'categoryId';
    private const string POINTER = '/categoryId';
    private const string MUST_BE_ID_OR_NULL = 'Must be a category id or null.';

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $body
     */
    public static function optional(#[\SensitiveParameter] array $body): string|FieldError|null
    {
        $value = $body[self::MEMBER] ?? null;

        return $value === null || is_string($value) ? $value : new FieldError(self::POINTER, self::MUST_BE_ID_OR_NULL);
    }
}
