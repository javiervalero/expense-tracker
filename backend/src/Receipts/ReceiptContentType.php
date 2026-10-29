<?php

declare(strict_types=1);

namespace App\Receipts;

/**
 * The four file types a receipt may have (C11), by their exact MIME string; {@see self::tryFrom()} refuses anything
 * else. {@see self::detect()} names the type by the file's first bytes, which is what the worker compares with the
 * type the client declared. A file that starts with anything else (a BOM, whitespace, SVG, HTML, GIF) has no type.
 */
enum ReceiptContentType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Pdf = 'application/pdf';

    private const string JPEG_SIGNATURE = "\xFF\xD8\xFF";
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";
    private const string PDF_SIGNATURE = '%PDF-';

    /** Magic bytes at offset 0; null when none of the four matches (including a file shorter than its signature). */
    public static function detect(string $bytes): ?self
    {
        return match (true) {
            str_starts_with($bytes, self::JPEG_SIGNATURE) => self::Jpeg,
            str_starts_with($bytes, self::PNG_SIGNATURE) => self::Png,
            // RIFF, a 4-byte length, then WEBP.
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::Webp,
            str_starts_with($bytes, self::PDF_SIGNATURE) => self::Pdf,
            default => null,
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Pdf => 'pdf',
        };
    }

    public function isImage(): bool
    {
        return $this !== self::Pdf;
    }
}
