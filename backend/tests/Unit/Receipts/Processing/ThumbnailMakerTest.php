<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts\Processing;

use App\Receipts\Processing\ThumbnailMaker;
use App\Tests\Support\Receipts\ReceiptFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ThumbnailMakerTest extends TestCase
{
    public function testShrinksAWideTransparentPngToAJpegOnWhite(): void
    {
        $thumbnail = (new ThumbnailMaker())->make(ReceiptFixtures::png(2000, 1000, true));

        $size = getimagesizefromstring($thumbnail);
        self::assertIsArray($size);
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
        self::assertSame(480, $size[0]);
        self::assertSame(240, $size[1]);
        $image = imagecreatefromstring($thumbnail);
        self::assertNotFalse($image);
        $rgb = imagecolorat($image, 0, 0);
        self::assertGreaterThanOrEqual(250, ($rgb >> 16) & 0xFF);
        self::assertGreaterThanOrEqual(250, ($rgb >> 8) & 0xFF);
        self::assertGreaterThanOrEqual(250, $rgb & 0xFF);
    }

    public function testFitsATallImageInsideTheBox(): void
    {
        $size = getimagesizefromstring((new ThumbnailMaker())->make(ReceiptFixtures::jpeg(600, 1200)));

        self::assertIsArray($size);
        self::assertSame(240, $size[0]);
        self::assertSame(480, $size[1]);
    }

    public function testDoesNotUpscaleASmallImage(): void
    {
        $size = getimagesizefromstring((new ThumbnailMaker())->make(ReceiptFixtures::png(100, 50)));

        self::assertIsArray($size);
        self::assertSame(100, $size[0]);
        self::assertSame(50, $size[1]);
    }

    public function testThumbnailsAWebpToo(): void
    {
        $size = getimagesizefromstring((new ThumbnailMaker())->make(ReceiptFixtures::webp(960, 960)));

        self::assertIsArray($size);
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
        self::assertSame(480, $size[0]);
        self::assertSame(480, $size[1]);
    }

    public function testOutputCarriesNoExifSegment(): void
    {
        $source = ReceiptFixtures::jpegWithOrientation(200, 100, 1);
        self::assertStringContainsString("\xFF\xE1", $source);
        self::assertStringContainsString('Exif', $source);

        $thumbnail = (new ThumbnailMaker())->make($source);

        self::assertStringNotContainsString("\xFF\xE1", $thumbnail);
        self::assertStringNotContainsString('Exif', $thumbnail);
    }

    /**
     * The source is 200 x 100 with a 50 x 50 red block top-left. Per orientation: the output size and the corner the
     * block ends up in (t = top, b = bottom, l = left, r = right).
     *
     * @return array<string, array{int, int, int, string}>
     */
    public static function orientations(): array
    {
        return [
            '1 as is' => [1, 200, 100, 'tl'],
            '2 mirrored horizontally' => [2, 200, 100, 'tr'],
            '3 turned 180' => [3, 200, 100, 'br'],
            '4 mirrored vertically' => [4, 200, 100, 'bl'],
            '5 transposed' => [5, 100, 200, 'tl'],
            '6 turned 90 clockwise' => [6, 100, 200, 'tr'],
            '7 transversed' => [7, 100, 200, 'br'],
            '8 turned 90 anticlockwise' => [8, 100, 200, 'bl'],
        ];
    }

    #[DataProvider('orientations')]
    public function testAppliesExifOrientation(int $orientation, int $width, int $height, string $corner): void
    {
        if (!extension_loaded('exif')) {
            self::markTestSkipped('exif is not loaded');
        }

        $thumbnail = (new ThumbnailMaker())->make(ReceiptFixtures::jpegWithOrientation(200, 100, $orientation));

        $size = getimagesizefromstring($thumbnail);
        self::assertIsArray($size);
        self::assertSame($width, $size[0]);
        self::assertSame($height, $size[1]);
        $image = imagecreatefromstring($thumbnail);
        self::assertNotFalse($image);
        $centres = [
            'tl' => [25, 25],
            'tr' => [$width - 25, 25],
            'bl' => [25, $height - 25],
            'br' => [$width - 25, $height - 25],
        ];
        foreach ($centres as $name => [$x, $y]) {
            self::assertSame($name === $corner, self::isRed((int) imagecolorat($image, $x, $y)), "corner $name, expected block in $corner");
        }
    }

    public function testAppliesOrientationToAnImageThatIsAlsoScaled(): void
    {
        if (!extension_loaded('exif')) {
            self::markTestSkipped('exif is not loaded');
        }

        $size = getimagesizefromstring((new ThumbnailMaker())->make(ReceiptFixtures::jpegWithOrientation(1200, 600, 6)));

        self::assertIsArray($size);
        self::assertSame(240, $size[0]);
        self::assertSame(480, $size[1]);
    }

    public function testIgnoresAnOrientationOutsideOneToEight(): void
    {
        $size = getimagesizefromstring((new ThumbnailMaker())->make(ReceiptFixtures::jpegWithOrientation(200, 100, 9)));

        self::assertIsArray($size);
        self::assertSame(200, $size[0]);
        self::assertSame(100, $size[1]);
    }

    private static function isRed(int $rgb): bool
    {
        return (($rgb >> 16) & 0xFF) > 200 && (($rgb >> 8) & 0xFF) < 80 && ($rgb & 0xFF) < 80;
    }

    public function testThrowsOnBytesThatAreNotAnImage(): void
    {
        $this->expectException(RuntimeException::class);

        (new ThumbnailMaker())->make('not an image');
    }
}
