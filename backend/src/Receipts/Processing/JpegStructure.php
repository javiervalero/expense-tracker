<?php

declare(strict_types=1);

namespace App\Receipts\Processing;

/**
 * A strict reader of a JPEG's marker structure, run before PHP's own header reader.
 *
 * PHP's `getimagesizefromstring` treats restart markers (FFD0 to FFD7), TEM (FF01) and stuffed FF00 before the scan as
 * segments with a length, while libjpeg (and so GD) treats them as markers with no parameters. A file can use that to
 * show PHP a small SOF while GD allocates the real, larger one. So this walker accepts, from SOI to the first scan,
 * only well-formed length-prefixed segments (APPn, COM, DQT, DHT, DRI and one SOF0, SOF1 or SOF2) and refuses anything
 * else. It returns the size from that one SOF.
 *
 * After a scan it skips the entropy-coded data (FF00, restart markers and fill bytes stay inside it), then accepts only
 * DHT, DQT, DRI, a further scan, or EOI. The file must reach EOI; whatever follows EOI (a Motion Photo or Samsung SEFT
 * trailer) is ignored. More than {@see self::MAX_SCANS} scans is refused, so a progressive file cannot make the
 * decoder do an unbounded amount of work.
 */
final readonly class JpegStructure
{
    public const int MAX_SCANS = 100;

    private const int EOI = 0xD9;
    private const int SOS = 0xDA;
    private const int DHT = 0xC4;
    private const int DQT = 0xDB;
    private const int DRI = 0xDD;
    private const int COM = 0xFE;
    private const int SOF_MIN = 0xC0;
    private const int SOF_MAX = 0xC2;
    private const int APP_MIN = 0xE0;
    private const int APP_MAX = 0xEF;
    private const int RST_MIN = 0xD0;
    private const int RST_MAX = 0xD7;

    private function __construct(
        public int $width,
        public int $height,
    ) {}

    /** The size from the file's one SOF, or null when the structure is not one this walker accepts. */
    public static function parse(string $bytes): ?self
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }
        $end = strlen($bytes);
        $position = 2;
        $size = null;
        $scans = 0;

        while ($position + 1 < $end) {
            $marker = self::markerAt($bytes, $position);
            if ($marker === null) {
                return null;
            }
            if ($marker === self::EOI) {
                return $scans > 0 && $size !== null ? new self($size[0], $size[1]) : null;
            }
            if (!self::isAllowed($marker, $scans > 0)) {
                return null;
            }
            $length = self::lengthAt($bytes, $position + 2, $end);
            if ($length === null) {
                return null;
            }
            if ($marker >= self::SOF_MIN && $marker <= self::SOF_MAX) {
                $size = self::acceptSof($bytes, $position, $length, $size);
                if ($size === null) {
                    return null;
                }
            }
            $position += 2 + $length;
            if ($marker !== self::SOS) {
                continue;
            }
            ++$scans;
            if ($size === null || $scans > self::MAX_SCANS) {
                return null;
            }
            $position = self::endOfScanData($bytes, $position);
            if ($position === null) {
                return null;
            }
        }

        return null;
    }

    /** The marker byte after an FF at `$position`, or null when it is not a real marker (no FF, fill byte, FF00). */
    private static function markerAt(string $bytes, int $position): ?int
    {
        if ($bytes[$position] !== "\xFF") {
            return null;
        }
        $marker = ord($bytes[$position + 1]);

        return $marker === 0x00 || $marker === 0xFF ? null : $marker;
    }

    private static function isAllowed(int $marker, bool $afterFirstScan): bool
    {
        if ($marker === self::SOS || $marker === self::DHT || $marker === self::DQT || $marker === self::DRI) {
            return true;
        }

        // The rest may only come before the first scan.
        return !$afterFirstScan && (
            ($marker >= self::SOF_MIN && $marker <= self::SOF_MAX)
            || ($marker >= self::APP_MIN && $marker <= self::APP_MAX)
            || $marker === self::COM
        );
    }

    /** The length field at `$position` (it counts itself), or null when it is below 2 or runs past the end. */
    private static function lengthAt(string $bytes, int $position, int $end): ?int
    {
        if ($position + 2 > $end) {
            return null;
        }
        $length = (ord($bytes[$position]) << 8) | ord($bytes[$position + 1]);

        return $length >= 2 && $position + $length <= $end ? $length : null;
    }

    /**
     * The size from the SOF segment at `$position`, or null when it is a second SOF or malformed.
     *
     * @param array{int, int}|null $sizeSoFar the size from an earlier SOF, if there was one
     *
     * @return array{int, int}|null
     */
    private static function acceptSof(string $bytes, int $position, int $length, ?array $sizeSoFar): ?array
    {
        if ($sizeSoFar !== null) {
            return null;
        }

        return self::sizeIn($bytes, $position + 4, $length - 2);
    }

    /**
     * @return array{int, int}|null width and height from a SOF payload (precision, height, width, ...)
     */
    private static function sizeIn(string $bytes, int $payloadStart, int $payloadLength): ?array
    {
        if ($payloadLength < 5) {
            return null;
        }
        $height = (ord($bytes[$payloadStart + 1]) << 8) | ord($bytes[$payloadStart + 2]);
        $width = (ord($bytes[$payloadStart + 3]) << 8) | ord($bytes[$payloadStart + 4]);

        return $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    /** The position of the first marker after the entropy-coded data that starts at `$position`, null when there is none. */
    private static function endOfScanData(string $bytes, int $position): ?int
    {
        $end = strlen($bytes);
        while (($found = strpos($bytes, "\xFF", $position)) !== false) {
            if ($found + 1 >= $end) {
                return null;
            }
            $next = ord($bytes[$found + 1]);
            if ($next === 0xFF) {
                // A run of fill bytes: the marker, if any, follows the last FF of the run.
                $position = $found + strspn($bytes, "\xFF", $found) - 1;

                continue;
            }
            if ($next === 0x00 || ($next >= self::RST_MIN && $next <= self::RST_MAX)) {
                $position = $found + 2;

                continue;
            }

            return $found;
        }

        return null;
    }
}
