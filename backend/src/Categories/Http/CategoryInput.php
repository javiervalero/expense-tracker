<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Categories\CategoryName;
use App\Http\Input\MemberErrors;
use App\Http\Input\ObjectMembers;
use App\Http\Input\StringMember;
use App\Http\Input\VersionMember;
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
    private const string NAME = 'name';
    private const string VERSION = 'version';
    private const string NAME_POINTER = '/name';

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws ValidationProblem
     */
    public static function newCategory(#[\SensitiveParameter] array $body): CategoryName
    {
        $name = self::name($body);
        $unknown = ObjectMembers::unknownErrors($body, [self::NAME]);
        if ($name instanceof FieldError || $unknown !== []) {
            throw new ValidationProblem([...MemberErrors::of($name), ...$unknown]);
        }

        return $name;
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws ValidationProblem
     */
    public static function rename(#[\SensitiveParameter] array $body): CategoryRenameInput
    {
        $name = self::name($body);
        $version = VersionMember::required($body);
        $unknown = ObjectMembers::unknownErrors($body, [self::NAME, self::VERSION]);
        if ($name instanceof FieldError || $version instanceof FieldError || $unknown !== []) {
            throw new ValidationProblem([...MemberErrors::of($name), ...MemberErrors::of($version), ...$unknown]);
        }

        return new CategoryRenameInput($name, $version);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function name(#[\SensitiveParameter] array $body): CategoryName|FieldError
    {
        $text = StringMember::required($body, self::NAME);
        if ($text instanceof FieldError) {
            return $text;
        }
        $name = CategoryName::parse($text);

        return $name instanceof CategoryName ? $name : new FieldError(self::NAME_POINTER, CategoryFieldMessages::name($name));
    }
}
