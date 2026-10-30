<?php

declare(strict_types=1);

namespace App\Receipts\Processing;

/**
 * A strict reader of a PNG's chunk structure, run before PHP's own header reader (the PNG twin of {@see JpegStructure}).
 *
 * libpng inflates compressed text chunks (`zTXt`, a compressed `iTXt`, `iCCP`) while it reads the file, outside PHP's
 * `memory_limit`, and a small file can hold many of them. Instead of measuring that, this walker refuses the chunks that
 * cause it. From the signature to IEND it requires:
 *
 * - IHDR first, exactly once, 13 bytes, with a non-zero size (the size PHP reads from fixed offsets is then the one
 *   libpng uses);
 * - every chunk to be length-prefixed, with a four-letter type, and to end inside the file;
 * - no `zTXt`, no `iTXt` whose compression flag is 1, and at most one `iCCP`;
 * - at most {@see self::MAX_CHUNKS} chunks other than IDAT. IDAT is not counted: it is one stream whose size the file
 *   size already bounds, and encoders that write 8 KiB chunks put more than 1,000 of them in a 10 MB screenshot;
 * - the walk to reach IEND. What follows IEND is ignored.
 *
 * Everything else passes: PLTE, IDAT, IEND, the ordinary ancillary chunks (tRNS, gAMA, cHRM, sRGB, pHYs, tEXt, tIME,
 * bKGD, eXIf, cICP) and unknown ancillary ones, which libpng discards. Checksums are not verified here; libpng does
 * that when the image is decoded.
 */
final readonly class PngStructure
{
    public const int MAX_CHUNKS = 1000;

    private const string SIGNATURE = "\x89PNG\r\n\x1A\n";
    private const int IHDR_LENGTH = 13;
    private const int MAX_CHUNK_LENGTH = 0x7FFFFFFF;
    /** A keyword is 1 to 79 bytes; the compression flag follows its terminator. */
    private const int ITXT_PROBE_BYTES = 82;

    private function __construct(
        public int $width,
        public int $height,
    ) {}

    /** The size from the file's IHDR, or null when the structure is not one this walker accepts. */
    public static function parse(string $bytes): ?self
    {
        if (!str_starts_with($bytes, self::SIGNATURE)) {
            return null;
        }
        $end = strlen($bytes);
        $position = strlen(self::SIGNATURE);
        $counted = 0;
        $profiles = 0;
        $size = null;

        while ($position + 8 <= $end) {
            $length = self::uint32At($bytes, $position);
            $type = substr($bytes, $position + 4, 4);
            $next = $position + 12 + $length;
            if ($length > self::MAX_CHUNK_LENGTH || $next > $end || preg_match('/\A[A-Za-z]{4}\z/', $type) !== 1) {
                return null;
            }
            if ($size === null) {
                // The first chunk must be IHDR; it is the only way to get a size.
                $size = $type === 'IHDR' ? self::sizeIn($bytes, $position + 8, $length) : null;
                if ($size === null) {
                    return null;
                }
            } elseif ($type === 'IHDR') {
                return null;
            }
            if ($type !== 'IDAT' && ++$counted > self::MAX_CHUNKS) {
                return null;
            }
            if ($type === 'iCCP') {
                ++$profiles;
            }
            if ($profiles > 1 || !self::textIsPlain($bytes, $type, $position + 8, $length)) {
                return null;
            }
            if ($type === 'IEND') {
                return new self($size[0], $size[1]);
            }
            $position = $next;
        }

        return null;
    }

    /**
     * @return array{int, int}|null width and height of an IHDR payload, null when it is not 13 bytes or a side is zero
     */
    private static function sizeIn(string $bytes, int $payloadStart, int $length): ?array
    {
        if ($length !== self::IHDR_LENGTH) {
            return null;
        }
        $width = self::uint32At($bytes, $payloadStart);
        $height = self::uint32At($bytes, $payloadStart + 4);

        return $width >= 1 && $height >= 1 ? [$width, $height] : null;
    }

    /** The big-endian unsigned 32-bit integer at `$position`; the caller has checked that four bytes are there. */
    private static function uint32At(string $bytes, int $position): int
    {
        return (ord($bytes[$position]) << 24) | (ord($bytes[$position + 1]) << 16) | (ord($bytes[$position + 2]) << 8) | ord($bytes[$position + 3]);
    }

    /** False for zTXt and for an iTXt that is compressed or whose compression flag cannot be read. */
    private static function textIsPlain(string $bytes, string $type, int $payloadStart, int $length): bool
    {
        if ($type === 'zTXt') {
            return false;
        }
        if ($type !== 'iTXt') {
            return true;
        }
        $probe = substr($bytes, $payloadStart, min($length, self::ITXT_PROBE_BYTES));
        $terminator = strpos($probe, "\x00");
        $flag = $terminator === false ? null : ($probe[$terminator + 1] ?? null);

        return $flag === "\x00";
    }
}
