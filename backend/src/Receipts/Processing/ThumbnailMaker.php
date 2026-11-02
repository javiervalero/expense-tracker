<?php

declare(strict_types=1);

namespace App\Receipts\Processing;

use GdImage;
use RuntimeException;

/**
 * Makes the receipt thumbnail (plan C12): fits inside 480 x 480, never upscales, is flattened onto white (so
 * transparency does not turn black) and is re-encoded as a JPEG at quality 80, which drops every metadata segment.
 * The EXIF orientation of a JPEG is applied when the `exif` extension is loaded (decision 4). The caller passes bytes
 * that {@see FileInspector} already accepted; anything GD cannot read is a RuntimeException.
 *
 * The image is fitted first and turned afterwards. The box is square, so the result is the same, and the turn works on
 * the small copy instead of making a second full-size one.
 */
final class ThumbnailMaker implements ThumbnailRenderer
{
    private const int THUMBNAIL_SIDE = 480;
    private const int JPEG_QUALITY = 80;

    public function make(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('The receipt image cannot be decoded');
        }

        $fitted = self::fitted($source);
        unset($source);
        $thumbnail = self::oriented($fitted, self::orientationOf($bytes));
        unset($fitted);

        ob_start();
        try {
            $written = imagejpeg($thumbnail, null, self::JPEG_QUALITY);
        } finally {
            $jpeg = (string) ob_get_clean();
        }
        unset($thumbnail);

        if (!$written || $jpeg === '') {
            throw new RuntimeException('The thumbnail cannot be encoded');
        }

        return $jpeg;
    }

    private static function fitted(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::THUMBNAIL_SIDE / $width, self::THUMBNAIL_SIDE / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            throw new RuntimeException('GD cannot create the thumbnail canvas');
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    /** The EXIF orientation (1 to 8) of a JPEG, 1 when there is none or the exif extension is not loaded. */
    private static function orientationOf(string $bytes): int
    {
        if (!extension_loaded('exif') || !str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $exif = @exif_read_data($stream, 'IFD0');
        } finally {
            fclose($stream);
        }

        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** Turns the pixels so the image shows upright; imagerotate counts degrees counter-clockwise. */
    private static function oriented(GdImage $image, int $orientation): GdImage
    {
        $flip = match ($orientation) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => null,
        };
        // 5 is a transpose (mirror, then a quarter turn anticlockwise) and 7 a transverse (mirror, then clockwise).
        $degrees = match ($orientation) {
            3 => 180.0,
            5, 8 => 90.0,
            6, 7 => 270.0,
            default => 0.0,
        };

        if ($flip !== null) {
            imageflip($image, $flip); // Declared to return true in PHP 8.5: it has no failure to report.
        }
        if ($degrees === 0.0) {
            return $image;
        }
        $rotated = imagerotate($image, $degrees, 0);
        if ($rotated === false) {
            throw new RuntimeException('GD cannot turn the thumbnail');
        }

        return $rotated;
    }
}
