<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories\Http;

use App\Categories\Http\CategoryInput;
use App\Http\Problem\ValidationProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryInputTest extends TestCase
{
    /**
     * @param callable(): mixed $parse
     *
     * @return list<array{pointer: string, detail: string}>
     */
    private static function errorsOf(callable $parse): array
    {
        try {
            $parse();
        } catch (ValidationProblem $problem) {
            $errors = $problem->problem->extensions['errors'] ?? null;
            self::assertIsArray($errors);

            $list = [];
            foreach ($errors as $error) {
                self::assertIsArray($error);
                self::assertIsString($error['pointer'] ?? null);
                self::assertIsString($error['detail'] ?? null);
                $list[] = ['pointer' => $error['pointer'], 'detail' => $error['detail']];
            }

            return $list;
        }
        self::fail('expected a ValidationProblem');
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<string>
     */
    private static function newPointers(array $body): array
    {
        return array_map(static fn(array $error): string => $error['pointer'], self::errorsOf(static fn(): mixed => CategoryInput::newCategory($body)));
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<string>
     */
    private static function renamePointers(array $body): array
    {
        return array_map(static fn(array $error): string => $error['pointer'], self::errorsOf(static fn(): mixed => CategoryInput::rename($body)));
    }

    public function testANewCategoryIsExactlyAName(): void
    {
        $name = CategoryInput::newCategory(['name' => '  Food  ']);

        self::assertSame('Food', $name->value());
    }

    public function testARenameIsANameAndAVersion(): void
    {
        $input = CategoryInput::rename(['name' => 'Travel', 'version' => 3]);

        self::assertSame('Travel', $input->name->value());
        self::assertSame(3, $input->version);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, list<string>}>
     */
    public static function badNewBodies(): iterable
    {
        yield 'a number' => [['name' => 5], ['/name']];
        yield 'null' => [['name' => null], ['/name']];
        yield 'a list' => [['name' => ['Food']], ['/name']];
        yield 'a boolean' => [['name' => true], ['/name']];
        yield 'missing' => [[], ['/name']];
        yield 'blank' => [['name' => '   '], ['/name']];
        yield 'too long' => [['name' => str_repeat('a', 51)], ['/name']];
        yield 'an unknown member' => [['name' => 'Food', 'colour' => 'red'], ['/colour']];
        yield 'a version is not allowed on a create' => [['name' => 'Food', 'version' => 1], ['/version']];
        yield 'a wrong name and an unknown member' => [['name' => 5, 'extra' => 1], ['/name', '/extra']];
    }

    /**
     * @param array<array-key, mixed> $body
     * @param list<string>            $pointers
     */
    #[DataProvider('badNewBodies')]
    public function testABadNewCategoryIsReportedAtTheRightPointers(array $body, array $pointers): void
    {
        self::assertSame($pointers, self::newPointers($body));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, list<string>}>
     */
    public static function badRenameBodies(): iterable
    {
        yield 'a string version' => [['name' => 'x', 'version' => '1'], ['/version']];
        yield 'a float version' => [['name' => 'x', 'version' => 1.0], ['/version']];
        yield 'a fractional version' => [['name' => 'x', 'version' => 1.5], ['/version']];
        yield 'a boolean version' => [['name' => 'x', 'version' => true], ['/version']];
        yield 'a null version' => [['name' => 'x', 'version' => null], ['/version']];
        yield 'a big integer version (a string once decoded)' => [['name' => 'x', 'version' => '99999999999999999999'], ['/version']];
        yield 'a zero version' => [['name' => 'x', 'version' => 0], ['/version']];
        yield 'a negative version' => [['name' => 'x', 'version' => -1], ['/version']];
        yield 'a missing version' => [['name' => 'x'], ['/version']];
        yield 'a missing name' => [['version' => 1], ['/name']];
        yield 'a missing name and version' => [[], ['/name', '/version']];
        yield 'a number name' => [['name' => 5, 'version' => 1], ['/name']];
        yield 'a null name' => [['name' => null, 'version' => 1], ['/name']];
        yield 'a bad name and a bad version' => [['name' => 5, 'version' => 0], ['/name', '/version']];
        yield 'an unknown member' => [['name' => 'x', 'version' => 1, 'id' => 'y'], ['/id']];
    }

    /**
     * @param array<array-key, mixed> $body
     * @param list<string>            $pointers
     */
    #[DataProvider('badRenameBodies')]
    public function testABadRenameIsReportedAtTheRightPointers(array $body, array $pointers): void
    {
        self::assertSame($pointers, self::renamePointers($body));
    }

    public function testTheLargestIntegerVersionIsAccepted(): void
    {
        self::assertSame(PHP_INT_MAX, CategoryInput::rename(['name' => 'x', 'version' => PHP_INT_MAX])->version);
    }

    public function testAtMostFiveUnknownMembersAreReportedAndNoMessageEchoesTheInput(): void
    {
        $body = ['name' => 'secret-marker-name'];
        foreach (range(1, 9) as $number) {
            $body['extra' . $number . '-secret-marker'] = 'secret-marker-value';
        }

        $errors = self::errorsOf(static fn(): mixed => CategoryInput::newCategory($body));

        self::assertCount(\App\Http\Input\ObjectMembers::MAX_UNKNOWN_KEY_ERRORS, $errors);
        foreach ($errors as $error) {
            self::assertStringNotContainsString('secret-marker-value', $error['detail']);
        }
    }

    public function testNoNameMessageEchoesTheName(): void
    {
        $errors = self::errorsOf(static fn(): mixed => CategoryInput::newCategory(['name' => "secret-marker\x00"]));

        self::assertSame('/name', $errors[0]['pointer']);
        self::assertStringNotContainsString('secret-marker', $errors[0]['detail']);
    }

    public function testControlAndBidiCharactersAreRefusedAtName(): void
    {
        self::assertSame(['/name'], self::newPointers(['name' => "a\u{202E}b"]));
        self::assertSame(['/name'], self::renamePointers(['name' => "a\x07b", 'version' => 1]));
    }
}
