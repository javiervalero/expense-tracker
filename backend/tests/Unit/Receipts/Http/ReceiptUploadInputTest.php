<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts\Http;

use App\Http\Problem\ValidationProblem;
use App\Receipts\Http\ReceiptFieldMessages;
use App\Receipts\Http\ReceiptUploadInput;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptUploadInputTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<array{pointer: string, detail: string}>
     */
    private static function errorsOf(array $body): array
    {
        try {
            ReceiptUploadInput::fromBody($body);
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

    /** @return iterable<string, array{string, ReceiptContentType}> */
    public static function acceptedTypes(): iterable
    {
        foreach (ReceiptContentType::cases() as $type) {
            yield $type->value => [$type->value, $type];
        }
    }

    #[DataProvider('acceptedTypes')]
    public function testEachOfTheFourTypesIsAccepted(string $text, ReceiptContentType $expected): void
    {
        $input = ReceiptUploadInput::fromBody(['contentType' => $text, 'size' => 1234]);

        self::assertSame($expected, $input->contentType);
        self::assertSame(1234, $input->size);
    }

    /** @return iterable<string, array{int}> */
    public static function acceptedSizes(): iterable
    {
        yield 'the smallest' => [1];
        yield 'the largest' => [ReceiptLimits::MAX_BYTES];
    }

    #[DataProvider('acceptedSizes')]
    public function testTheBoundarySizesAreAccepted(int $size): void
    {
        self::assertSame($size, ReceiptUploadInput::fromBody(['contentType' => 'image/png', 'size' => $size])->size);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badTypes(): iterable
    {
        yield 'gif' => ['image/gif'];
        yield 'svg' => ['image/svg+xml'];
        yield 'html' => ['text/html'];
        yield 'upper case' => ['IMAGE/PNG'];
        yield 'with a parameter' => ['image/png; charset=utf-8'];
        yield 'padded' => [' image/png'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'a number' => [7];
        yield 'a list' => [['image/png']];
        yield 'a boolean' => [true];
    }

    #[DataProvider('badTypes')]
    public function testATypeOutsideTheFourIsA422AtContentType(mixed $type): void
    {
        self::assertSame(['/contentType'], self::pointersOf(['contentType' => $type, 'size' => 100]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function badSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'one over the limit' => [ReceiptLimits::MAX_BYTES + 1];
        yield 'a float' => [100.5];
        yield 'a whole float' => [100.0];
        yield 'a numeric string' => ['100'];
        yield 'an integer beyond 64 bits (arrives as a string)' => ['99999999999999999999'];
        yield 'null' => [null];
        yield 'a boolean' => [true];
        yield 'a list' => [[1]];
    }

    #[DataProvider('badSizes')]
    public function testASizeThatIsNotAnIntegerFromOneToTheLimitIsA422AtSize(mixed $size): void
    {
        self::assertSame(['/size'], self::pointersOf(['contentType' => 'image/png', 'size' => $size]));
    }

    public function testAMissingTypeAndSizeAreBothReportedInMemberOrder(): void
    {
        $errors = self::errorsOf([]);

        self::assertSame(['/contentType', '/size'], array_map(static fn(array $error): string => $error['pointer'], $errors));
        self::assertSame('This field is required.', $errors[0]['detail']);
        self::assertSame('This field is required.', $errors[1]['detail']);
    }

    public function testAnUnknownMemberIsA422AtItsPointer(): void
    {
        self::assertSame(['/filename'], self::pointersOf(['contentType' => 'image/png', 'size' => 5, 'filename' => 'a.png']));
    }

    public function testAListBodyIsRefusedAsMissingMembersPlusUnknownOnes(): void
    {
        self::assertSame(['/contentType', '/size', '/0'], self::pointersOf(['image/png']));
    }

    public function testAMessageNamesTheRuleAndNeverRepeatsTheValue(): void
    {
        $errors = self::errorsOf(['contentType' => 'image/x-secret-marker', 'size' => 424242424]);

        self::assertSame(ReceiptFieldMessages::contentType(), $errors[0]['detail']);
        self::assertSame(ReceiptFieldMessages::size(), $errors[1]['detail']);
        foreach ($errors as $error) {
            self::assertStringNotContainsString('secret-marker', $error['detail']);
            self::assertStringNotContainsString('424242424', $error['detail']);
        }
    }

    public function testTheMessagesStateTheRules(): void
    {
        self::assertSame('Must be one of image/jpeg, image/png, image/webp, application/pdf.', ReceiptFieldMessages::contentType());
        self::assertSame('Must be a whole number of bytes from 1 to ' . ReceiptLimits::MAX_BYTES . '.', ReceiptFieldMessages::size());
    }
}
