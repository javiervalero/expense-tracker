<?php

declare(strict_types=1);

namespace App\Expenses\Http;

use App\Expenses\Amount;
use App\Expenses\AmountViolation;
use App\Expenses\Description;
use App\Expenses\ExpenseDate;
use App\Http\Input\ObjectMembers;
use App\Http\Input\StringMember;
use App\Http\Input\TextViolation;
use App\Http\Problem\FieldError;
use App\Http\Problem\ValidationProblem;

/**
 * The decoded body of `POST /expenses`: `{date, amountCents, description, categoryId?}` and nothing else. Every member
 * is checked for its JSON type before a value object is built from it, so a number, `null`, boolean or list where a
 * string is wanted (and a float, string or boolean for the amount) is a `422` at its pointer and never a `TypeError`
 * (a `500`). An integer beyond 64 bits arrives as a string ({@see \App\Http\JsonBody}) and is refused as one.
 *
 * `categoryId` is absent or `null` (uncategorized) or a string. Whether the string is one of the caller's categories
 * needs a read, so the handler decides that with the resolver; here only its JSON type is checked.
 *
 * All the errors are reported together, in member order, then the unknown members (at most 5, safe pointers: the rules
 * of {@see ObjectMembers}). A message names the member and never repeats a value or a key.
 */
final readonly class NewExpenseInput
{
    private const string DATE = 'date';
    private const string AMOUNT = 'amountCents';
    private const string DESCRIPTION = 'description';
    private const string CATEGORY = 'categoryId';
    private const string REQUIRED = 'This field is required.';

    private function __construct(
        public ExpenseDate $date,
        public Amount $amount,
        public Description $description,
        public ?string $categoryId,
    ) {}

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws ValidationProblem
     */
    public static function fromBody(#[\SensitiveParameter] array $body): self
    {
        $date = self::date($body);
        $amount = self::amount($body);
        $description = self::description($body);
        $category = self::category($body);
        $unknown = ObjectMembers::unknownErrors($body, [self::DATE, self::AMOUNT, self::DESCRIPTION, self::CATEGORY]);
        $errors = [...self::errorsOf($date), ...self::errorsOf($amount), ...self::errorsOf($description), ...self::errorsOf($category), ...$unknown];
        // The instanceof checks only narrow the types for static analysis; $errors is non-empty whenever one is true.
        if ($errors !== [] || $date instanceof FieldError || $amount instanceof FieldError || $description instanceof FieldError || $category instanceof FieldError) {
            throw new ValidationProblem($errors);
        }

        return new self($date, $amount, $description, $category);
    }

    /**
     * @return list<FieldError>
     */
    private static function errorsOf(mixed $member): array
    {
        return $member instanceof FieldError ? [$member] : [];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function date(#[\SensitiveParameter] array $body): ExpenseDate|FieldError
    {
        $text = StringMember::required($body, self::DATE);
        if ($text instanceof FieldError) {
            return $text;
        }

        return ExpenseDate::parse($text) ?? new FieldError('/' . self::DATE, ExpenseFieldMessages::date());
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function amount(#[\SensitiveParameter] array $body): Amount|FieldError
    {
        if (!array_key_exists(self::AMOUNT, $body)) {
            return new FieldError('/' . self::AMOUNT, self::REQUIRED);
        }
        $amount = Amount::parse($body[self::AMOUNT]);

        return $amount instanceof AmountViolation ? new FieldError('/' . self::AMOUNT, ExpenseFieldMessages::amount($amount)) : $amount;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function description(#[\SensitiveParameter] array $body): Description|FieldError
    {
        $text = StringMember::required($body, self::DESCRIPTION);
        if ($text instanceof FieldError) {
            return $text;
        }
        $description = Description::parse($text);

        return $description instanceof TextViolation ? new FieldError('/' . self::DESCRIPTION, ExpenseFieldMessages::description($description)) : $description;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function category(#[\SensitiveParameter] array $body): string|FieldError|null
    {
        $value = $body[self::CATEGORY] ?? null;

        return $value === null || is_string($value) ? $value : new FieldError('/' . self::CATEGORY, 'Must be a category id or null.');
    }
}
