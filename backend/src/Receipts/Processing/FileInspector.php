<?php

declare(strict_types=1);

namespace App\Receipts\Processing;

use App\Receipts\FailureReason;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;

/**
 * Decides whether a stored object is the receipt the client said it was (plan C11). The order is the defence:
 *
 * 1. the first bytes must name exactly the declared type (SVG, HTML, GIF and the rest have no type);
 * 2. for a JPEG, {@see JpegStructure} walks the markers strictly and gives the size libjpeg will use; for a PNG,
 *    {@see PngStructure} walks the chunks strictly (IHDR first, no compressed text, one profile at most);
 * 3. for an image, the header alone (`getimagesizefromstring`, which does not decode) must agree on the type and the
 *    size, and the size must be inside the limits. `imagecreatefromstring` allocates width x height x 4 bytes before it
 *    reads a pixel, so a 50,000 x 50,000 header must be refused before any decode;
 * 4. only then the image is decoded, to prove it really is an image.
 *
 * Every PHP or GD notice or warning raised from step 2 on is captured, never thrown: it makes the file unreadable. An
 * invalid file is a normal outcome for the worker, not a failure to retry.
 *
 * PDFs are not parsed: the magic bytes are all that is checked.
 */
final class FileInspector
{
    public function inspect(string $bytes, ReceiptContentType $declared): Inspection
    {
        if (ReceiptContentType::detect($bytes) !== $declared) {
            return Inspection::refused(FailureReason::UnsupportedType);
        }
        if (!$declared->isImage()) {
            return Inspection::accepted($declared);
        }

        $warned = false;
        $isPng = $declared === ReceiptContentType::Png;
        set_error_handler(static function (int $level, string $message) use (&$warned, $isPng): bool {
            // libpng reports a harmless oddity (an odd colour profile, say) as "libpng warning" and still decodes.
            $warned = $warned || !($isPng && str_contains($message, 'libpng warning'));

            return true;
        });

        try {
            $result = self::inspectImage($bytes, $declared);
        } finally {
            restore_error_handler();
        }

        return $warned && $result->isAccepted() ? Inspection::refused(FailureReason::Unreadable) : $result;
    }

    private static function inspectImage(string $bytes, ReceiptContentType $declared): Inspection
    {
        // PHP reads a PNG's size from fixed offsets without checking the chunk there; libpng skips an ancillary chunk
        // placed before IHDR and uses the real one. PngStructure requires IHDR first, so both read the same bytes (and
        // it refuses the compressed chunks libpng inflates outside PHP's memory limit).
        // One arm per type, each returning: a new image type fails to compile here instead of skipping a walker.
        return match ($declared) {
            ReceiptContentType::Jpeg => self::inspectWith(JpegStructure::parse($bytes), $bytes, $declared),
            ReceiptContentType::Png => self::inspectWith(PngStructure::parse($bytes), $bytes, $declared),
            ReceiptContentType::Webp => self::inspectHeader($bytes, $declared, null), // libwebp refuses a canvas and frame mismatch
            ReceiptContentType::Pdf => throw new \LogicException('A PDF is not an image.'),
        };
    }

    /** A walker that found no acceptable structure refuses the file; otherwise the header must agree with it. */
    private static function inspectWith(JpegStructure|PngStructure|null $structure, string $bytes, ReceiptContentType $declared): Inspection
    {
        return $structure === null
            ? Inspection::refused(FailureReason::Unreadable)
            : self::inspectHeader($bytes, $declared, $structure);
    }

    private static function inspectHeader(string $bytes, ReceiptContentType $declared, JpegStructure|PngStructure|null $structure): Inspection
    {
        $header = getimagesizefromstring($bytes);
        if ($header === false) {
            return Inspection::refused(FailureReason::Unreadable);
        }
        [$width, $height, $imageType] = $header;
        if (self::typeOf($imageType) !== $declared) {
            return Inspection::refused(FailureReason::UnsupportedType);
        }
        if ($structure !== null && ($structure->width !== $width || $structure->height !== $height)) {
            return Inspection::refused(FailureReason::Unreadable);
        }
        if (!self::sizeIsAllowed($width, $height)) {
            return Inspection::refused(FailureReason::TooLarge);
        }
        if (!self::decodes($bytes)) {
            return Inspection::refused(FailureReason::Unreadable);
        }

        return Inspection::accepted($declared, $width, $height);
    }

    private static function sizeIsAllowed(int $width, int $height): bool
    {
        return $width >= 1 && $height >= 1
            && $width <= ReceiptLimits::MAX_SIDE && $height <= ReceiptLimits::MAX_SIDE
            && $width * $height <= ReceiptLimits::MAX_PIXELS;
    }

    private static function typeOf(int $imageType): ?ReceiptContentType
    {
        return match ($imageType) {
            IMAGETYPE_JPEG => ReceiptContentType::Jpeg,
            IMAGETYPE_PNG => ReceiptContentType::Png,
            IMAGETYPE_WEBP => ReceiptContentType::Webp,
            default => null,
        };
    }

    /** Decodes once and frees the image. */
    private static function decodes(string $bytes): bool
    {
        $image = imagecreatefromstring($bytes);
        if ($image === false) {
            return false;
        }
        unset($image);

        return true;
    }
}
