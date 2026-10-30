<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts\Processing;

use App\Receipts\Processing\JpegStructure;
use App\Tests\Support\Receipts\ReceiptFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JpegStructureTest extends TestCase
{
    private const string SOI = "\xFF\xD8";
    private const string EOI = "\xFF\xD9";

    public function testReadsTheSizeOfARealJpeg(): void
    {
        $structure = JpegStructure::parse(ReceiptFixtures::jpeg(40, 30));

        self::assertNotNull($structure);
        self::assertSame(40, $structure->width);
        self::assertSame(30, $structure->height);
    }

    public function testSkipsApp1AndIgnoresWhatFollowsEoi(): void
    {
        self::assertNotNull(JpegStructure::parse(ReceiptFixtures::jpegWithOrientation(200, 100, 6)));
        self::assertNotNull(JpegStructure::parse(ReceiptFixtures::jpegWithTrailer()));
    }

    public function testAcceptsStuffedBytesAndRestartMarkersInsideScanData(): void
    {
        $jpeg = self::SOI . self::sof() . self::sos() . "\x11\xFF\x00\x22\xFF\xD3\x33\xFF" . self::EOI;

        self::assertNotNull(JpegStructure::parse($jpeg));
    }

    public function testAcceptsTablesAndFurtherScansBetweenScans(): void
    {
        $jpeg = self::SOI . self::sof(0xC2) . self::sos() . "\x01"
            . ReceiptFixtures::jpegSegment(0xC4, "\x00" . str_repeat("\x00", 16))
            . ReceiptFixtures::jpegSegment(0xDB, "\x00" . str_repeat("\x01", 64))
            . ReceiptFixtures::jpegSegment(0xDD, "\x00\x04")
            . self::sos() . "\x02" . self::EOI;

        self::assertNotNull(JpegStructure::parse($jpeg));
    }

    public function testAcceptsASingleBaselineScanAndAnExtendedSequentialFrame(): void
    {
        self::assertNotNull(JpegStructure::parse(self::SOI . self::sof(0xC0) . self::sos() . "\x01" . self::EOI));
        self::assertNotNull(JpegStructure::parse(self::SOI . self::sof(0xC1) . self::sos() . "\x01" . self::EOI));
    }

    public function testAcceptsExactlyTheMaximumNumberOfScans(): void
    {
        self::assertNotNull(JpegStructure::parse(ReceiptFixtures::jpegWithScans(JpegStructure::MAX_SCANS)));
    }

    public function testRefusesMoreThanTheMaximumNumberOfScans(): void
    {
        self::assertNull(JpegStructure::parse(ReceiptFixtures::jpegWithScans(JpegStructure::MAX_SCANS + 1)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformed(): array
    {
        $sof = self::sof();
        $sos = self::sos();

        return [
            'empty' => [''],
            'no SOI' => ["\x00\x00" . $sof . $sos . self::EOI],
            'SOI only' => [self::SOI],
            'garbage after SOI' => [self::SOI . "\x00" . $sof . $sos . self::EOI],
            'fill byte before a marker' => [self::SOI . "\xFF" . $sof . $sos . self::EOI],
            'stuffed FF00 before SOS' => [self::SOI . "\xFF\x00" . $sof . $sos . self::EOI],
            'RST0 before SOS' => [self::SOI . "\xFF\xD0" . $sof . $sos . self::EOI],
            'RST7 before SOS' => [self::SOI . "\xFF\xD7" . $sof . $sos . self::EOI],
            'TEM before SOS' => [self::SOI . "\xFF\x01" . $sof . $sos . self::EOI],
            'second SOI before SOS' => [self::SOI . self::SOI . $sof . $sos . self::EOI],
            'two SOF' => [self::SOI . $sof . $sof . $sos . self::EOI],
            'SOF3 (lossless)' => [self::SOI . self::sof(0xC3) . $sos . self::EOI],
            'SOF9 (arithmetic)' => [self::SOI . self::sof(0xC9) . $sos . self::EOI],
            'DAC marker' => [self::SOI . ReceiptFixtures::jpegSegment(0xCC, "\x00\x00") . $sof . $sos . self::EOI],
            'length past the end' => [self::SOI . "\xFF\xE0\xFF\xFF" . 'short'],
            'length below two' => [self::SOI . "\xFF\xFE\x00\x01" . $sof . $sos . self::EOI],
            'length cut off' => [self::SOI . "\xFF\xE0\x00"],
            'SOS before SOF' => [self::SOI . $sos . self::EOI],
            'no SOF at all' => [self::SOI . ReceiptFixtures::jpegSegment(0xFE, 'hi') . self::EOI],
            'EOI before SOS' => [self::SOI . $sof . self::EOI],
            'zero width' => [self::SOI . self::sof(0xC0, 0, 5) . $sos . self::EOI],
            'zero height' => [self::SOI . self::sof(0xC0, 5, 0) . $sos . self::EOI],
            'SOF too short for a size' => [self::SOI . ReceiptFixtures::jpegSegment(0xC0, "\x08\x00") . $sos . self::EOI],
            'scan cut short' => [self::SOI . $sof . $sos . "\x11\x22\x33"],
            'unknown marker after a scan' => [self::SOI . $sof . $sos . "\x11" . ReceiptFixtures::jpegSegment(0xE0, 'x') . self::EOI],
            'SOF after the first scan' => [self::SOI . $sof . $sos . "\x11" . $sof . $sos . self::EOI],
            // Stricter than libjpeg on purpose: only tables and scans may sit between scans.
            'COM after a scan' => [self::SOI . $sof . $sos . "\x11" . ReceiptFixtures::jpegSegment(0xFE, 'hi') . self::EOI],
            'APP1 after a scan' => [self::SOI . $sof . $sos . "\x11" . ReceiptFixtures::jpegSegment(0xE1, 'hi') . self::EOI],
            'DNL after a scan' => [self::SOI . $sof . $sos . "\x11" . ReceiptFixtures::jpegSegment(0xDC, "\x00\x08") . self::EOI],
            'lone FF as the last byte of the scan data' => [self::SOI . $sof . $sos . "\x11\x22\xFF"],
            'scan header past the end' => [self::SOI . $sof . "\xFF\xDA\x00\x20\x01"],
        ];
    }

    #[DataProvider('malformed')]
    public function testRefusesMalformedStructure(string $bytes): void
    {
        self::assertNull(JpegStructure::parse($bytes));
    }

    public function testRefusesAJpegCutInsideItsScan(): void
    {
        self::assertNull(JpegStructure::parse(ReceiptFixtures::truncatedJpeg()));
    }

    public function testRefusesACutJpegWhoseExifHoldsACompleteThumbnail(): void
    {
        self::assertNull(JpegStructure::parse(ReceiptFixtures::truncatedJpegWithExifThumbnail()));
    }

    public function testRefusesAJpegWhoseSofIsHiddenBehindARestartMarker(): void
    {
        self::assertNull(JpegStructure::parse(ReceiptFixtures::desyncedJpeg(13_000, 10, 10, 10)));
    }

    private static function sof(int $marker = 0xC0, int $width = 5, int $height = 5): string
    {
        return ReceiptFixtures::jpegSegment($marker, ReceiptFixtures::sofPayload($width, $height));
    }

    private static function sos(): string
    {
        return ReceiptFixtures::jpegSegment(0xDA, "\x01\x01\x00\x00\x3F\x00");
    }
}
