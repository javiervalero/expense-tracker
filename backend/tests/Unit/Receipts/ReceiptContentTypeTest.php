<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts;

use App\Receipts\ReceiptContentType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptContentTypeTest extends TestCase
{
    public function testExactlyTheFourMimeStringsAreAccepted(): void
    {
        self::assertSame(
            ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
            $this->sorted(array_map(static fn(ReceiptContentType $t): string => $t->value, ReceiptContentType::cases())),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedMimeTypes(): array
    {
        return [
            'image/jpg' => ['image/jpg'],
            'upper case' => ['IMAGE/JPEG'],
            'svg' => ['image/svg+xml'],
            'html' => ['text/html'],
            'gif' => ['image/gif'],
            'with parameter' => ['image/jpeg; charset=utf-8'],
            'trailing space' => ['image/png '],
            'empty' => [''],
        ];
    }

    #[DataProvider('refusedMimeTypes')]
    public function testOtherMimeStringsAreRefused(string $mime): void
    {
        self::assertNull(ReceiptContentType::tryFrom($mime));
    }

    /**
     * @return array<string, array{string, ?ReceiptContentType}>
     */
    public static function prefixes(): array
    {
        return [
            'jpeg' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", ReceiptContentType::Jpeg],
            'jpeg, bare signature' => ["\xFF\xD8\xFF", ReceiptContentType::Jpeg],
            'png' => ["\x89PNG\r\n\x1A\n\x00\x00", ReceiptContentType::Png],
            'png, bare signature' => ["\x89PNG\r\n\x1A\n", ReceiptContentType::Png],
            'png, truncated signature' => ["\x89PNG\r\n\x1A", null],
            'webp' => ["RIFF\x24\x00\x00\x00WEBPVP8 ", ReceiptContentType::Webp],
            'webp, bare' => ['RIFF1234WEBP', ReceiptContentType::Webp],
            'webp, truncated' => ['RIFF1234WEB', null],
            'pdf' => ["%PDF-1.7\n", ReceiptContentType::Pdf],
            'pdf, bare' => ['%PDF-', ReceiptContentType::Pdf],
            'pdf, truncated' => ['%PDF', null],
            'two bytes' => ["\xFF\xD8", null],
            'one byte' => ['%', null],
            'wav' => ["RIFF\x24\x00\x00\x00WAVEfmt ", null],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', null],
            'html' => ['<!DOCTYPE html><html>', null],
            'gif' => ['GIF89a....', null],
            'utf-8 bom before pdf' => ["\xEF\xBB\xBF%PDF-1.4", null],
            'leading space before pdf' => [' %PDF-1.4', null],
            'signature not at offset 0' => ["xx\xFF\xD8\xFF", null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('prefixes')]
    public function testDetectByMagicBytes(string $bytes, ?ReceiptContentType $expected): void
    {
        self::assertSame($expected, ReceiptContentType::detect($bytes));
    }

    public function testExtensions(): void
    {
        self::assertSame('jpg', ReceiptContentType::Jpeg->extension());
        self::assertSame('png', ReceiptContentType::Png->extension());
        self::assertSame('webp', ReceiptContentType::Webp->extension());
        self::assertSame('pdf', ReceiptContentType::Pdf->extension());
    }

    public function testOnlyThePdfIsNotAnImage(): void
    {
        self::assertTrue(ReceiptContentType::Jpeg->isImage());
        self::assertTrue(ReceiptContentType::Png->isImage());
        self::assertTrue(ReceiptContentType::Webp->isImage());
        self::assertFalse(ReceiptContentType::Pdf->isImage());
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
