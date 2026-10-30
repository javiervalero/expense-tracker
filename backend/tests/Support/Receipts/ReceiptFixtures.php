<?php

declare(strict_types=1);

namespace App\Tests\Support\Receipts;

use GdImage;
use RuntimeException;

/**
 * Receipt files built inside the test: real images drawn with GD, and header-only files written byte by byte (a
 * declared size with no pixel data behind it). Nothing is downloaded or read from disk.
 */
final class ReceiptFixtures
{
    private function __construct() {}

    public static function png(int $width, int $height, bool $transparent = false): string
    {
        $image = self::canvas($width, $height);
        if ($transparent) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        }

        return self::encode($image, static fn(GdImage $i): bool => imagepng($i));
    }

    public static function jpeg(int $width, int $height): string
    {
        return self::encode(self::canvas($width, $height), static fn(GdImage $i): bool => imagejpeg($i, null, 90));
    }

    public static function webp(int $width, int $height): string
    {
        return self::encode(self::canvas($width, $height), static fn(GdImage $i): bool => imagewebp($i, null, 80));
    }

    /** A JPEG with a red block in the top-left corner and an EXIF orientation tag (any orientation 1-8; 6 = rotate 90 degrees clockwise to display). */
    public static function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        if ($image === false) {
            throw new RuntimeException('GD cannot create a canvas');
        }
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 49, 49, (int) imagecolorallocate($image, 255, 0, 0));
        $jpeg = self::encode($image, static fn(GdImage $i): bool => imagejpeg($i, null, 95));

        // APP1: "Exif\0\0", a big-endian TIFF header, and one IFD0 entry: tag 0x0112 (Orientation), type SHORT, count 1.
        $tiff = "MM\x00\x2A" . pack('N', 8)
            . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\x00\x00"
            . pack('N', 0);

        return self::insertApp1($jpeg, "Exif\x00\x00" . $tiff);
    }

    /** A PNG that is only the signature and an IHDR chunk with a correct CRC: the declared size, and no pixel data. */
    public static function pngHeaderOnly(int $width, int $height): string
    {
        $data = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1A\n" . self::pngChunk('IHDR', $data);
    }

    /** A real PNG with an extra iCCP chunk (a profile name and bytes that are not a zlib stream) after the IHDR. */
    public static function pngWithBrokenProfile(): string
    {
        $png = self::png(10, 10);
        $iccp = self::pngChunk('iCCP', "x\x00\x00" . 'not a zlib stream');

        return substr($png, 0, 33) . $iccp . substr($png, 33);
    }

    /**
     * A complete PNG whose first chunk is an unknown ancillary one (`prVt`) holding 10 x 10 IHDR-shaped data, ahead of
     * the real IHDR. PHP's header reader takes the size from fixed offsets and reports the decoy; libpng skips the
     * ancillary chunk and uses the real IHDR. The real size is modest on purpose.
     */
    public static function pngWithDecoyChunkBeforeIhdr(int $realWidth, int $realHeight): string
    {
        $decoy = pack('NN', 10, 10) . "\x08\x02\x00\x00\x00";
        $real = pack('NN', $realWidth, $realHeight) . "\x08\x02\x00\x00\x00";
        $rows = str_repeat("\x00" . str_repeat("\x00", $realWidth * 3), $realHeight);

        return "\x89PNG\r\n\x1A\n"
            . self::pngChunk('prVt', $decoy)
            . self::pngChunk('IHDR', $real)
            . self::pngChunk('IDAT', (string) gzcompress($rows))
            . self::pngChunk('IEND', '');
    }

    /** A WebP that is only the RIFF header and a VP8X chunk (extended format) declaring the canvas size. */
    public static function webpHeaderOnly(int $width, int $height): string
    {
        $chunk = 'VP8X' . pack('V', 10) . "\x00\x00\x00\x00" . substr(pack('V', $width - 1), 0, 3) . substr(pack('V', $height - 1), 0, 3);

        return 'RIFF' . pack('V', strlen($chunk) + 4) . 'WEBP' . $chunk;
    }

    /** A JPEG that is only SOI, a baseline SOF0 declaring the size, an empty scan and EOI: the declared size, and no pixel data. */
    public static function jpegHeaderOnly(int $width, int $height): string
    {
        return "\xFF\xD8" . self::jpegSegment(0xC0, self::sofPayload($width, $height)) . self::jpegSegment(0xDA, "\x01\x01\x00\x00\x3F\x00") . "\xFF\xD9";
    }

    /**
     * A JPEG whose first bytes after SOI are an RST0 marker "carrying" a length-prefixed segment. PHP's header reader
     * treats RST0 as a segment with a length and skips it, then reports the small SOF that follows; libjpeg treats RST0
     * as a marker with no parameters and reads the real SOF hidden in that segment. The hidden size is modest on purpose.
     */
    public static function desyncedJpeg(int $hiddenWidth, int $hiddenHeight, int $visibleWidth, int $visibleHeight): string
    {
        $hidden = self::jpegSegment(0xC0, self::sofPayload($hiddenWidth, $hiddenHeight));

        return "\xFF\xD8"
            . "\xFF\xD0" . pack('n', strlen($hidden) + 2) . $hidden
            . self::jpegSegment(0xC0, self::sofPayload($visibleWidth, $visibleHeight))
            . self::jpegSegment(0xDA, "\x01\x01\x00\x00\x3F\x00") . "\x12\x34\xFF\xD9";
    }

    /** A valid JPEG with a trailer after EOI, as Motion Photo and Samsung SEFT files have. */
    public static function jpegWithTrailer(): string
    {
        return self::jpeg(40, 30) . "SEFT\x00\x01\x02\x03 trailer bytes\x00";
    }

    /** A JPEG with extraneous bytes between SOI and the first marker. */
    public static function jpegWithExtraneousBytes(): string
    {
        $jpeg = self::jpeg(16, 16);

        return substr($jpeg, 0, 2) . "\x00\x00\x00" . substr($jpeg, 2);
    }

    /** A JPEG with `$scans` empty scans (each a start-of-scan header and no data) after a small SOF. */
    public static function jpegWithScans(int $scans): string
    {
        return "\xFF\xD8" . self::jpegSegment(0xC2, self::sofPayload(8, 8))
            . str_repeat(self::jpegSegment(0xDA, "\x01\x01\x00\x00\x3F\x00") . "\x00", $scans) . "\xFF\xD9";
    }

    /** A valid JPEG cut short: the header is intact, the pixel data is not. */
    public static function truncatedJpeg(): string
    {
        return self::cutShort(self::noisyJpeg());
    }

    /** A cut-short JPEG whose EXIF APP1 holds a complete thumbnail (with its own SOI, scan and EOI). */
    public static function truncatedJpegWithExifThumbnail(): string
    {
        return self::insertApp1(self::cutShort(self::noisyJpeg()), "Exif\x00\x00" . self::jpeg(16, 16));
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    public static function jpegSegment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker & 0xFF) . pack('n', strlen($payload) + 2) . $payload;
    }

    /** A baseline SOF payload: 8-bit precision, the size, one component. */
    public static function sofPayload(int $width, int $height): string
    {
        return "\x08" . pack('nn', $height, $width) . "\x01\x01\x11\x00";
    }

    private static function noisyJpeg(): string
    {
        $image = imagecreatetruecolor(64, 64);
        if ($image === false) {
            throw new RuntimeException('GD cannot create a canvas');
        }
        for ($x = 0; $x < 64; ++$x) {
            for ($y = 0; $y < 64; ++$y) {
                imagesetpixel($image, $x, $y, ((($x * 7) ^ ($y * 13)) << 8) | (($x * 3 + $y) & 0xFF));
            }
        }

        return self::encode($image, static fn(GdImage $i): bool => imagejpeg($i, null, 95));
    }

    private static function cutShort(string $jpeg): string
    {
        return substr($jpeg, 0, (int) (strlen($jpeg) * 0.4));
    }

    private static function insertApp1(string $jpeg, string $payload): string
    {
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        if ($image === false) {
            throw new RuntimeException('GD cannot create a canvas');
        }
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));

        return $image;
    }

    /**
     * @param callable(GdImage): bool $write
     */
    private static function encode(GdImage $image, callable $write): string
    {
        ob_start();
        try {
            $ok = $write($image);
        } finally {
            $bytes = (string) ob_get_clean();
        }
        if (!$ok || $bytes === '') {
            throw new RuntimeException('GD could not encode the fixture');
        }

        return $bytes;
    }
}
