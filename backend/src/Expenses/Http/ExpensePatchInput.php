<?php

declare(strict_types=1);

namespace App\Expenses\Http;

use App\Expenses\Amount;
use App\Expenses\AmountViolation;
use App\Expenses\Description;
use App\Expenses\ExpenseDate;
use App\Http\Input\CategoryIdMember;
use App\Http\Input\MemberErrors;
use App\Http\Input\ObjectMembers;
use App\Http\Input\StringMember;
use App\Http\Input\TextViolation;
use App\Http\Input\VersionMember;
use App\Http\Problem\FieldError;
use App\Http\Problem\ValidationProblem;

/**
 * The decoded body of `PATCH /expenses/{id}`: `{version, date?, amountCents?, description?, categoryId?}` and nothing
 * else (B11). A member that is present is changed and one that is absent is left alone (`null` in a value object
 * property, `setsCategory` for the category, where `null` is a value). `null` for `date`, `amountCents` or
 * `description` is a `422` at its pointer, like any wrong JSON type: every member is checked for its type before a value
 * object is built from it, so a wrong type is never a `TypeError` (a `500`). `version` is an integer of at least 1
 * (`12.0` and an integer beyond 64 bits, which {@see \App\Http\JsonBody} keeps as a string, are refused). At least one
 * editable member must be present, else the error is for the whole body (the empty pointer).
 *
 * `categoryId` is absent, `null` (uncategorized) or a string; whether the string is one of the caller's categories
 * needs a read, so the handler decides that with the resolver.
 *
 * All the errors are reported together, in member order, then the unknown members (at most 5, safe pointers: the rules
 * of {@see ObjectMembers}). A message names the member and never repeats a value or a key.
 */
final readonly class ExpensePatchInput
{
    private const string VERSION = 'version';
    private const string DATE = 'date';
    private const string AMOUNT = 'amountCents';
    private const string DESCRIPTION = 'description';
    private const string CATEGORY = 'categoryId';

    private function __construct(
        public int $version,
        public ?ExpenseDate $date,
        public ?Amount $amount,
        public ?Description $description,
        public bool $setsCategory,
        public ?string $categoryId,
    ) {}

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws ValidationProblem
     */
    public static function fromBody(#[\SensitiveParameter] array $body): self
    {
        $version = VersionMember::required($body);
        $date = self::date($body);
        $amount = self::amount($body);
        $description = self::description($body);
        $category = CategoryIdMember::optional($body);
        $errors = [
            ...MemberErrors::of($version),
            ...MemberErrors::of($date),
            ...MemberErrors::of($amount),
            ...MemberErrors::of($description),
            ...MemberErrors::of($category),
            ...self::noChangeErrors($body),
            ...ObjectMembers::unknownErrors($body, [self::VERSION, self::DATE, self::AMOUNT, self::DESCRIPTION, self::CATEGORY]),
        ];
        // The instanceof checks only narrow the types for static analysis; $errors is non-empty whenever one is true.
        if ($errors !== [] || $version instanceof FieldError || $date instanceof FieldError || $amount instanceof FieldError || $description instanceof FieldError || $category instanceof FieldError) {
            throw new ValidationProblem($errors);
        }

        return new self($version, $date, $amount, $description, array_key_exists(self::CATEGORY, $body), $category);
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<FieldError>
     */
    private static function noChangeErrors(#[\SensitiveParameter] array $body): array
    {
        foreach ([self::DATE, self::AMOUNT, self::DESCRIPTION, self::CATEGORY] as $member) {
            if (array_key_exists($member, $body)) {
                return [];
            }
        }

        return [new FieldError('', 'Change at least one of date, amountCents, description and categoryId.')];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function date(#[\SensitiveParameter] array $body): ExpenseDate|FieldError|null
    {
        $text = StringMember::optional($body, self::DATE);
        if ($text === null || $text instanceof FieldError) {
            return $text;
        }

        return ExpenseDate::parse($text) ?? new FieldError('/' . self::DATE, ExpenseFieldMessages::date());
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function amount(#[\SensitiveParameter] array $body): Amount|FieldError|null
    {
        if (!array_key_exists(self::AMOUNT, $body)) {
            return null;
        }
        $value = $body[self::AMOUNT];
        // Only a JSON integer can be an amount: Amount::parse refuses everything else, `null` and booleans included.
        $amount = Amount::parse($value);

        return $amount instanceof AmountViolation ? new FieldError('/' . self::AMOUNT, ExpenseFieldMessages::amount($amount)) : $amount;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function description(#[\SensitiveParameter] array $body): Description|FieldError|null
    {
        $text = StringMember::optional($body, self::DESCRIPTION);
        if ($text === null || $text instanceof FieldError) {
            return $text;
        }
        $description = Description::parse($text);

        return $description instanceof TextViolation ? new FieldError('/' . self::DESCRIPTION, ExpenseFieldMessages::description($description)) : $description;
    }
}
