<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Expenses\Http\NewExpenseInput;
use App\Http\Problem\ValidationProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NewExpenseInputTest extends TestCase
{
    private const CATEGORY = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<array{pointer: string, detail: string}>
     */
    private static function errorsOf(array $body): array
    {
        try {
            NewExpenseInput::fromBody($body);
        } catch (ValidationProblem $problem) {
            $errors = $problem->problem->extensions['errors'] ?? null;
            self::assertIsArray($errors);

            /** @var list<array{pointer: string, detail: string}> $errors */
            return $errors;
        }
        self::fail('expected a ValidationProblem');
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<string>
     */
    private static function pointersOf(array $body): array
    {
        return array_map(static fn(array $error): string => $error['pointer'], self::errorsOf($body));
    }

    public function testAValidBodyGivesTheTypedValues(): void
    {
        $input = NewExpenseInput::fromBody(['date' => '2026-10-20', 'amountCents' => 1250, 'description' => '  Lunch ', 'categoryId' => self::CATEGORY]);

        self::assertSame('2026-10-20', $input->date->value());
        self::assertSame(1250, $input->amount->cents());
        self::assertSame('Lunch', $input->description->value());
        self::assertSame(self::CATEGORY, $input->categoryId);
    }

    public function testAnAbsentOrNullCategoryIsUncategorized(): void
    {
        $absent = NewExpenseInput::fromBody(['date' => '2026-10-20', 'amountCents' => 1, 'description' => 'x']);
        $null = NewExpenseInput::fromBody(['date' => '2026-10-20', 'amountCents' => 100000000, 'description' => 'x', 'categoryId' => null]);

        self::assertNull($absent->categoryId);
        self::assertNull($null->categoryId);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badAmounts(): iterable
    {
        yield 'a float with a fraction' => [12.5];
        yield 'a whole float' => [12.0];
        yield 'exponent notation (decodes as a float)' => [1e2];
        yield 'a numeric string' => ['1250'];
        yield 'a beyond-64-bit integer (arrives as a string)' => ['99999999999999999999'];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'null' => [null];
        yield 'a list' => [[1250]];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above the maximum' => [100_000_001];
    }

    #[DataProvider('badAmounts')]
    public function testABadAmountIs422AtAmountCentsAndNeverATypeError(mixed $amount): void
    {
        self::assertSame(['/amountCents'], self::pointersOf(['date' => '2026-10-20', 'amountCents' => $amount, 'description' => 'x']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badDates(): iterable
    {
        yield 'a number' => [20261020];
        yield 'null' => [null];
        yield 'true' => [true];
        yield 'a list' => [['2026-10-20']];
        yield 'not a date' => ['yesterday'];
        yield 'impossible day' => ['2026-02-30'];
        yield 'before the range' => ['1999-12-31'];
        yield 'after the range' => ['2100-01-01'];
        yield 'a date-time' => ['2026-10-20T10:00:00Z'];
        yield 'non-ASCII digits' => ["2026-1\u{0660}-20"];
        yield 'trailing newline' => ["2026-10-20\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('badDates')]
    public function testABadDateIs422AtDate(mixed $date): void
    {
        self::assertSame(['/date'], self::pointersOf(['date' => $date, 'amountCents' => 1, 'description' => 'x']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badDescriptions(): iterable
    {
        yield 'a number' => [5];
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'a list' => [['x']];
        yield 'empty' => [''];
        yield 'blank' => ["  \t "];
        yield 'too long' => [str_repeat('a', 201)];
        yield 'NUL' => ["a\0b"];
        yield 'bidi override' => ["a\u{202E}b"];
        yield 'invisible only' => ["\u{200B}"];
        yield 'invalid UTF-8' => ["a\xC3\x28"];
    }

    #[DataProvider('badDescriptions')]
    public function testABadDescriptionIs422AtDescription(mixed $description): void
    {
        self::assertSame(['/description'], self::pointersOf(['date' => '2026-10-20', 'amountCents' => 1, 'description' => $description]));
    }

    public function testADescriptionOfExactlyTheMaximumIsAccepted(): void
    {
        $input = NewExpenseInput::fromBody(['date' => '2026-10-20', 'amountCents' => 1, 'description' => str_repeat('é', 200)]);

        self::assertSame(200, mb_strlen($input->description->value()));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringCategories(): iterable
    {
        yield 'a number' => [5];
        yield 'a list' => [[self::CATEGORY]];
        yield 'an empty list' => [[]];
        yield 'true' => [true];
        yield 'false' => [false];
    }

    #[DataProvider('nonStringCategories')]
    public function testANonStringCategoryIdIs422AtCategoryId(mixed $category): void
    {
        self::assertSame(['/categoryId'], self::pointersOf(['date' => '2026-10-20', 'amountCents' => 1, 'description' => 'x', 'categoryId' => $category]));
    }

    public function testMissingMembersAreAllReportedTogetherInMemberOrder(): void
    {
        $errors = self::errorsOf([]);

        self::assertSame(['/date', '/amountCents', '/description'], array_column($errors, 'pointer'));
        self::assertSame(['This field is required.'], array_values(array_unique(array_column($errors, 'detail'))));
    }

    public function testEveryBadMemberIsReportedInOneResponse(): void
    {
        $pointers = self::pointersOf(['date' => 'x', 'amountCents' => 1.5, 'description' => 5, 'categoryId' => 5, 'colour' => 'red']);

        self::assertSame(['/date', '/amountCents', '/description', '/categoryId', '/colour'], $pointers);
    }

    public function testAnUnknownMemberIs422AtItsPointer(): void
    {
        self::assertSame(['/colour'], self::pointersOf(['date' => '2026-10-20', 'amountCents' => 1, 'description' => 'x', 'colour' => 'red']));
    }

    public function testAnUnsafeUnknownKeyIsReportedForTheWholeBody(): void
    {
        self::assertSame([''], self::pointersOf(['date' => '2026-10-20', 'amountCents' => 1, 'description' => 'x', "a\0b" => 1]));
    }

    public function testNoMessageEchoesTheInput(): void
    {
        $errors = self::errorsOf(['date' => 'SECRET-D', 'amountCents' => 'SECRET-A', 'description' => ["SECRET-X"], 'categoryId' => ['SECRET-C']]);

        self::assertStringNotContainsString('SECRET', json_encode($errors, JSON_THROW_ON_ERROR));
    }
}
