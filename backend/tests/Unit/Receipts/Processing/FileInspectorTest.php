<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts\Processing;

use App\Receipts\FailureReason;
use App\Receipts\Processing\FileInspector;
use App\Receipts\ReceiptContentType;
use App\Tests\Support\Receipts\ReceiptFixtures;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileInspectorTest extends TestCase
{
    /** A header-only file must be refused from its header; decoding it would need gigabytes. */
    private const int DECODE_MEMORY_BOUND_BYTES = 8_388_608;

    /**
     * @return array<string, array{string, ReceiptContentType, int, int}>
     */
    public static function validImages(): array
    {
        return [
            'jpeg' => [ReceiptFixtures::jpeg(40, 30), ReceiptContentType::Jpeg, 40, 30],
            'png' => [ReceiptFixtures::png(25, 60), ReceiptContentType::Png, 25, 60],
            'webp' => [ReceiptFixtures::webp(33, 17), ReceiptContentType::Webp, 33, 17],
            'png with alpha' => [ReceiptFixtures::png(10, 10, true), ReceiptContentType::Png, 10, 10],
            'png at the side limit' => [ReceiptFixtures::png(12_000, 1), ReceiptContentType::Png, 12_000, 1],
        ];
    }

    #[DataProvider('validImages')]
    public function testAcceptsAValidImageAndReportsItsDimensions(string $bytes, ReceiptContentType $type, int $width, int $height): void
    {
        $result = (new FileInspector())->inspect($bytes, $type);

        self::assertTrue($result->isAccepted());
        self::assertNull($result->failure);
        self::assertSame($type, $result->type);
        self::assertSame($width, $result->width);
        self::assertSame($height, $result->height);
    }

    public function testAcceptsAPdfByItsMagicBytesOnly(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::pdf(), ReceiptContentType::Pdf);

        self::assertTrue($result->isAccepted());
        self::assertSame(ReceiptContentType::Pdf, $result->type);
        self::assertNull($result->width);
        self::assertNull($result->height);
    }

    public function testRefusesAPdfDeclaredAsAJpeg(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::pdf(), ReceiptContentType::Jpeg);

        self::assertFalse($result->isAccepted());
        self::assertSame(FailureReason::UnsupportedType, $result->failure);
    }

    public function testRefusesJpegBytesDeclaredAsAPng(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::jpeg(10, 10), ReceiptContentType::Png);

        self::assertFalse($result->isAccepted());
        self::assertSame(FailureReason::UnsupportedType, $result->failure);
    }

    public function testRefusesAPngDeclaredAsAPdf(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::png(10, 10), ReceiptContentType::Pdf);

        self::assertSame(FailureReason::UnsupportedType, $result->failure);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notAReceipt(): array
    {
        return [
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>'],
            'html' => ['<!doctype html><html><body><script>1</script></body></html>'],
            'gif' => ["GIF89a\x01\x00\x01\x00\x00\x00\x00;"],
            'empty' => [''],
            'bom then png signature' => ["\xEF\xBB\xBF" . "\x89PNG\r\n\x1A\n"],
        ];
    }

    #[DataProvider('notAReceipt')]
    public function testRefusesTextAndOtherFormatsDeclaredAsAPng(string $bytes): void
    {
        $result = (new FileInspector())->inspect($bytes, ReceiptContentType::Png);

        self::assertFalse($result->isAccepted());
        self::assertSame(FailureReason::UnsupportedType, $result->failure);
    }

    /**
     * @return array<string, array{string, ReceiptContentType}>
     */
    public static function oversizedHeaders(): array
    {
        return [
            'png 50000 x 50000' => [ReceiptFixtures::pngHeaderOnly(50_000, 50_000), ReceiptContentType::Png],
            'png 12001 x 1' => [ReceiptFixtures::pngHeaderOnly(12_001, 1), ReceiptContentType::Png],
            'png 1 x 12001' => [ReceiptFixtures::pngHeaderOnly(1, 12_001), ReceiptContentType::Png],
            'png 8000 x 5001 (over 40 MP)' => [ReceiptFixtures::pngHeaderOnly(8_000, 5_001), ReceiptContentType::Png],
            'webp 50000 x 50000' => [ReceiptFixtures::webpHeaderOnly(50_000, 50_000), ReceiptContentType::Webp],
            'webp 12001 x 1' => [ReceiptFixtures::webpHeaderOnly(12_001, 1), ReceiptContentType::Webp],
            'jpeg 50000 x 50000' => [ReceiptFixtures::jpegHeaderOnly(50_000, 50_000), ReceiptContentType::Jpeg],
            'jpeg 12001 x 1' => [ReceiptFixtures::jpegHeaderOnly(12_001, 1), ReceiptContentType::Jpeg],
        ];
    }

    #[DataProvider('oversizedHeaders')]
    public function testRefusesAnOversizedImageFromItsHeaderWithoutDecoding(string $bytes, ReceiptContentType $type): void
    {
        $inspector = new FileInspector();
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $result = $inspector->inspect($bytes, $type);

        $growth = memory_get_peak_usage() - $before;
        self::assertFalse($result->isAccepted());
        self::assertSame(FailureReason::TooLarge, $result->failure);
        self::assertLessThan(self::DECODE_MEMORY_BOUND_BYTES, $growth, 'the image was decoded before its size was checked');
    }

    public function testAcceptsTheLargestAllowedPixelCountFromItsHeader(): void
    {
        // 8000 x 5000 is exactly 40,000,000 pixels: allowed by the size check, so it reaches the decoder, which fails
        // on the missing data. Reaching "unreadable" (not "too-large") proves the boundary is inclusive.
        $result = (new FileInspector())->inspect(ReceiptFixtures::pngHeaderOnly(8_000, 5_000), ReceiptContentType::Png);

        self::assertSame(FailureReason::Unreadable, $result->failure);
    }

    public function testRefusesATruncatedJpegAsUnreadable(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::truncatedJpeg(), ReceiptContentType::Jpeg);

        self::assertFalse($result->isAccepted());
        self::assertSame(FailureReason::Unreadable, $result->failure);
    }

    public function testRefusesAJpegSignatureFollowedByGarbageAsUnreadable(): void
    {
        $result = (new FileInspector())->inspect("\xFF\xD8\xFF" . str_repeat("\x00", 40), ReceiptContentType::Jpeg);

        self::assertSame(FailureReason::Unreadable, $result->failure);
    }

    public function testRefusesAPngWithNoPixelDataAsUnreadable(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::pngHeaderOnly(10, 10), ReceiptContentType::Png);

        self::assertSame(FailureReason::Unreadable, $result->failure);
    }

    public function testAcceptsAJpegWithATrailerAfterEoi(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::jpegWithTrailer(), ReceiptContentType::Jpeg);

        self::assertTrue($result->isAccepted());
        self::assertSame(40, $result->width);
        self::assertSame(30, $result->height);
    }

    public function testRefusesACutJpegWhoseExifThumbnailIsComplete(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::truncatedJpegWithExifThumbnail(), ReceiptContentType::Jpeg);

        self::assertSame(FailureReason::Unreadable, $result->failure);
    }

    public function testRefusesAJpegWithMoreScansThanTheLimit(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::jpegWithScans(101), ReceiptContentType::Jpeg);

        self::assertSame(FailureReason::Unreadable, $result->failure);
    }

    /**
     * PHP's header reader and libjpeg disagree about a restart marker before the scan, so the size PHP reports can be a
     * decoy for the one GD allocates. Modest sizes only: 13,000 x 10 is over the side limit but costs about 0.5 MB.
     */
    public function testRefusesAJpegWhoseHeaderShowsADifferentSizeThanTheOneHiddenInIt(): void
    {
        $bytes = ReceiptFixtures::desyncedJpeg(13_000, 10, 10, 10);
        self::assertSame([10, 10], array_slice((array) getimagesizefromstring($bytes), 0, 2), 'the fixture no longer fools PHP');

        $result = (new FileInspector())->inspect($bytes, ReceiptContentType::Jpeg);

        self::assertFalse($result->isAccepted());
        self::assertSame(FailureReason::Unreadable, $result->failure);
    }

    public function testAcceptsAPngWithAProfileChunkThatLibpngMayComplainAbout(): void
    {
        $result = (new FileInspector())->inspect(ReceiptFixtures::pngWithBrokenProfile(), ReceiptContentType::Png);

        self::assertTrue($result->isAccepted());
        self::assertSame(10, $result->width);
    }

    /**
     * PHP's header reader looks at fixed offsets, so a decoy chunk ahead of the real IHDR shows it a small size while
     * libpng and GD use the real one. The first chunk must be IHDR.
     */
    public function testRefusesAPngWhoseFirstChunkIsNotIhdr(): void
    {
        $bytes = ReceiptFixtures::pngWithDecoyChunkBeforeIhdr(13_000, 10);
        self::assertSame([10, 10], array_slice((array) getimagesizefromstring($bytes), 0, 2), 'the fixture no longer fools PHP');

        $result = (new FileInspector())->inspect($bytes, ReceiptContentType::Png);

        self::assertFalse($result->isAccepted());
        self::assertSame(FailureReason::Unreadable, $result->failure);
    }

    /**
     * The worker's error handler may throw on any error (the front controller's does). Files that are cut short, mixed
     * up or malformed make the decoder and header reader warn; under a throwing handler each must still be a refusal,
     * never an exception: an invalid file is a normal outcome, not a failure.
     */
    public function testInvalidFilesNeverRaiseUnderAThrowingErrorHandler(): void
    {
        $cases = [
            [ReceiptFixtures::pngWithDecoyChunkBeforeIhdr(13_000, 10), ReceiptContentType::Png],
            [ReceiptFixtures::jpegWithExtraneousBytes(), ReceiptContentType::Jpeg],
            [ReceiptFixtures::truncatedJpeg(), ReceiptContentType::Jpeg],
            [ReceiptFixtures::desyncedJpeg(13_000, 10, 10, 10), ReceiptContentType::Jpeg],
            [substr(ReceiptFixtures::pngHeaderOnly(10, 10), 0, 20), ReceiptContentType::Png],
            [substr(ReceiptFixtures::png(10, 10), 0, 40), ReceiptContentType::Png],
            [substr(ReceiptFixtures::webp(10, 10), 0, 20), ReceiptContentType::Webp],
        ];
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            foreach ($cases as [$bytes, $type]) {
                $result = (new FileInspector())->inspect($bytes, $type);
                self::assertFalse($result->isAccepted());
            }
        } finally {
            restore_error_handler();
        }
    }

    public function testNoGdWarningEscapesAndTheErrorHandlerIsRestored(): void
    {
        $seen = [];
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            $inspector = new FileInspector();
            $inspector->inspect(ReceiptFixtures::truncatedJpeg(), ReceiptContentType::Jpeg);
            $inspector->inspect(ReceiptFixtures::pngHeaderOnly(10, 10), ReceiptContentType::Png);
            $inspector->inspect('<svg/>', ReceiptContentType::Png);
            $afterInspection = $seen;
            trigger_error('probe', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $afterInspection, 'a GD warning reached the surrounding error handler');
        self::assertSame(['probe'], $seen, 'the previous error handler was not restored');
    }

    public function testRestoresTheErrorHandlerAfterAnAcceptedImage(): void
    {
        $seen = [];
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            (new FileInspector())->inspect(ReceiptFixtures::png(5, 5), ReceiptContentType::Png);
            trigger_error('probe', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        self::assertSame(['probe'], $seen);
    }
}
