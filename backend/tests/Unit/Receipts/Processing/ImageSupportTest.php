<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts\Processing;

use PHPUnit\Framework\TestCase;

/** The dev image must carry the same image extensions as the worker image (plan decision 19). */
final class ImageSupportTest extends TestCase
{
    public function testGdIsLoadedWithJpegPngAndWebp(): void
    {
        self::assertTrue(extension_loaded('gd'), 'gd is missing from the dev image');
        $types = imagetypes();
        self::assertNotSame(0, $types & IMG_JPG, 'GD has no JPEG');
        self::assertNotSame(0, $types & IMG_PNG, 'GD has no PNG');
        self::assertNotSame(0, $types & IMG_WEBP, 'GD has no WebP');
    }

    public function testExifIsLoaded(): void
    {
        self::assertTrue(extension_loaded('exif'), 'exif is missing from the dev image');
    }
}
