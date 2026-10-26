<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Expenses\Http\ExpensePatchInput;
use App\Http\Problem\ValidationProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpensePatchInputTest extends TestCase
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
            ExpensePatchInput::fromBody($body);
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

    public function testEveryMemberPresentGivesTheTypedValues(): void
    {
        $input = ExpensePatchInput::fromBody(['version' => 3, 'date' => '2026-10-21', 'amountCents' => 99, 'description' => '  Dinner ', 'categoryId' => self::CATEGORY]);

        self::assertSame(3, $input->version);
        self::assertSame('2026-10-21', $input->date?->value());
        self::assertSame(99, $input->amount?->cents());
        self::assertSame('Dinner', $input->description?->value());
        self::assertTrue($input->setsCategory);
        self::assertSame(self::CATEGORY, $input->categoryId);
    }

    public function testAbsentMembersAreUnchanged(): void
    {
        $input = ExpensePatchInput::fromBody(['version' => 1, 'amountCents' => 5]);

        self::assertNull($input->date);
        self::assertNull($input->description);
        self::assertFalse($input->setsCategory);
        self::assertNull($input->categoryId);
        self::assertSame(5, $input->amount?->cents());
    }

    public function testAnExplicitNullCategoryIsASetToUncategorized(): void
    {
        $input = ExpensePatchInput::fromBody(['version' => 1, 'categoryId' => null]);

        self::assertTrue($input->setsCategory);
        self::assertNull($input->categoryId);
    }

    public function testAnEmptyBodyReportsTheMissingVersionAndTheMissingChange(): void
    {
        self::assertSame(['/version', ''], self::pointersOf([]));
    }

    public function testVersionAloneIsAnErrorAtTheRoot(): void
    {
        self::assertSame([''], self::pointersOf(['version' => 1]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'date null' => [['date' => null], '/date'];
        yield 'date number' => [['date' => 20261020], '/date'];
        yield 'date invalid' => [['date' => '2026-02-30'], '/date'];
        yield 'amount null' => [['amountCents' => null], '/amountCents'];
        yield 'amount float' => [['amountCents' => 12.5], '/amountCents'];
        yield 'amount whole float' => [['amountCents' => 12.0], '/amountCents'];
        yield 'amount string' => [['amountCents' => '12'], '/amountCents'];
        yield 'amount bool' => [['amountCents' => true], '/amountCents'];
        yield 'amount zero' => [['amountCents' => 0], '/amountCents'];
        yield 'amount too large' => [['amountCents' => 100000001], '/amountCents'];
        yield 'description null' => [['description' => null], '/description'];
        yield 'description number' => [['description' => 5], '/description'];
        yield 'description blank' => [['description' => '   '], '/description'];
        yield 'description list' => [['description' => ['x']], '/description'];
        yield 'category number' => [['categoryId' => 5], '/categoryId'];
        yield 'category bool' => [['categoryId' => false], '/categoryId'];
        yield 'category list' => [['categoryId' => [self::CATEGORY]], '/categoryId'];
    }

    /**
     * @param array<string, mixed> $member
     */
    #[DataProvider('wrongTypes')]
    public function testAWrongMemberIs422AtItsPointer(array $member, string $pointer): void
    {
        self::assertSame([$pointer], self::pointersOf(['version' => 1, ...$member]));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badVersions(): iterable
    {
        yield 'zero' => [0, '/version'];
        yield 'negative' => [-1, '/version'];
        yield 'float' => [1.5, '/version'];
        yield 'whole float' => [1.0, '/version'];
        yield 'string' => ['1', '/version'];
        yield 'bigint as string' => ['99999999999999999999', '/version'];
        yield 'bool' => [true, '/version'];
        yield 'null' => [null, '/version'];
        yield 'list' => [[1], '/version'];
    }

    #[DataProvider('badVersions')]
    public function testABadVersionIs422AtVersion(mixed $version, string $pointer): void
    {
        self::assertSame([$pointer], self::pointersOf(['version' => $version, 'amountCents' => 5]));
    }

    public function testAMissingVersionIs422AtVersion(): void
    {
        self::assertSame(['/version'], self::pointersOf(['amountCents' => 5]));
    }

    public function testAnUnknownMemberIs422AndNeverRepeatsTheKey(): void
    {
        $errors = self::errorsOf(['version' => 1, 'amountCents' => 5, 'colour' => 'red']);

        self::assertSame(['/colour'], array_map(static fn(array $error): string => $error['pointer'], $errors));
        self::assertStringNotContainsString('colour', $errors[0]['detail']);
    }

    public function testVersionPlusAnUnknownMemberReportsTheNoChangeErrorThenTheMember(): void
    {
        self::assertSame(['', '/colour'], self::pointersOf(['version' => 1, 'colour' => 'x']));
    }

    public function testAnIdOrTimestampMemberIsUnknownNotEditable(): void
    {
        self::assertSame(['/id'], self::pointersOf(['version' => 1, 'amountCents' => 5, 'id' => self::CATEGORY]));
    }

    public function testAllErrorsAreReportedTogetherInMemberOrder(): void
    {
        self::assertSame(
            ['/version', '/date', '/amountCents', '/description', '/categoryId', '/extra'],
            self::pointersOf(['extra' => 1, 'categoryId' => 7, 'description' => null, 'amountCents' => 'x', 'date' => 1, 'version' => 0]),
        );
    }
}
