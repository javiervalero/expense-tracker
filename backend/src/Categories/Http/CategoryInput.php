<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Categories\CategoryName;
use App\Http\Input\ObjectMembers;
use App\Http\Problem\FieldError;
use App\Http\Problem\ValidationProblem;

/**
 * The decoded bodies of the category endpoints: `NewCategory` is exactly `{name}`, `CategoryRename` is exactly
 * `{name, version}`. Every member is checked for its JSON type before a value object is built from it, so a number or
 * `null` for the name, or a float, string or boolean for the version, is a `422` at its pointer and never a `TypeError`
 * (a `500`). `version` is an integer of at least 1; `12.0` decodes as a float and is refused, and so is an integer
 * beyond 64 bits (which {@see \App\Http\JsonBody} keeps as a string).
 *
 * All the errors are reported together, in member order, then the unknown members (at most 5, safe pointers: the rules
 * of {@see ObjectMembers}). A message names the member and never repeats a value or a key.
 */
final class CategoryInput
{
    private const NAME = 'name';
    private const VERSION = 'version';
    private const NAME_POINTER = '/name';
    private const VERSION_POINTER = '/version';
    private const MIN_VERSION = 1;

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws ValidationProblem
     */
    public static function newCategory(array $body): CategoryName
    {
        $name = self::name($body);
        $unknown = ObjectMembers::unknownErrors($body, [self::NAME]);
        if ($name instanceof FieldError || $unknown !== []) {
            throw new ValidationProblem([...self::problemsOf($name), ...$unknown]);
        }

        return $name;
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws ValidationProblem
     */
    public static function rename(array $body): CategoryRenameInput
    {
        $name = self::name($body);
        $version = self::version($body);
        $unknown = ObjectMembers::unknownErrors($body, [self::NAME, self::VERSION]);
        if ($name instanceof FieldError || $version instanceof FieldError || $unknown !== []) {
            throw new ValidationProblem([...self::problemsOf($name), ...self::problemsOf($version), ...$unknown]);
        }

        return new CategoryRenameInput($name, $version);
    }

    /**
     * @return list<FieldError>
     */
    private static function problemsOf(CategoryName|int|FieldError $member): array
    {
        return $member instanceof FieldError ? [$member] : [];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function name(array $body): CategoryName|FieldError
    {
        if (!array_key_exists(self::NAME, $body)) {
            return new FieldError(self::NAME_POINTER, 'This field is required.');
        }
        $value = $body[self::NAME];
        if (!is_string($value)) {
            return new FieldError(self::NAME_POINTER, 'Must be a string.');
        }
        $name = CategoryName::parse($value);

        return $name instanceof CategoryName ? $name : new FieldError(self::NAME_POINTER, CategoryFieldMessages::name($name));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function version(array $body): int|FieldError
    {
        if (!array_key_exists(self::VERSION, $body)) {
            return new FieldError(self::VERSION_POINTER, 'This field is required.');
        }
        $value = $body[self::VERSION];
        if (!is_int($value)) {
            return new FieldError(self::VERSION_POINTER, 'Must be an integer.');
        }

        return $value < self::MIN_VERSION ? new FieldError(self::VERSION_POINTER, 'Must be at least ' . self::MIN_VERSION . '.') : $value;
    }
}
