<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts\Processing;

use App\Receipts\Processing\PngStructure;
use App\Tests\Support\Receipts\ReceiptFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PngStructureTest extends TestCase
{
    /**
     * @return array{string, string}
     */
    private static function ihdr(int $width = 4, int $height = 3): array
    {
        return ['IHDR', ReceiptFixtures::pngIhdrData($width, $height)];
    }

    /**
     * @param list<array{string, string}> $between chunks placed after IHDR and before IDAT
     */
    private static function png(array $between = []): string
    {
        return ReceiptFixtures::pngOfChunks([self::ihdr(), ...$between, ['IDAT', "\x00"], ['IEND', '']]);
    }

    public function testReadsTheSizeOfARealPng(): void
    {
        $structure = PngStructure::parse(ReceiptFixtures::png(25, 60));

        self::assertNotNull($structure);
        self::assertSame(25, $structure->width);
        self::assertSame(60, $structure->height);
    }

    public function testAcceptsTheOrdinaryAncillaryChunksOfAScreenshot(): void
    {
        $chunks = [
            ['iCCP', "sRGB\x00\x00" . (string) gzcompress('profile')],
            ['sRGB', "\x00"],
            ['gAMA', pack('N', 45455)],
            ['cHRM', str_repeat("\x00", 32)],
            ['pHYs', pack('NNC', 2835, 2835, 1)],
            ['tEXt', "Software\x00test"],
            ['tIME', str_repeat("\x00", 7)],
            ['tRNS', "\x00"],
            ['bKGD', "\x00\x00\x00\x00\x00\x00"],
            ['eXIf', 'II*'],
            ['cICP', "\x01\x0D\x00\x01"],
            ['prVt', 'unknown ancillary'],
            ['iTXt', ReceiptFixtures::itxtData(false)],
        ];

        self::assertNotNull(PngStructure::parse(self::png($chunks)));
    }

    public function testRefusesZtxtAlways(): void
    {
        self::assertNull(PngStructure::parse(self::png([['zTXt', ReceiptFixtures::ztxtData()]])));
    }

    public function testRefusesACompressedItxtAndAcceptsAPlainOne(): void
    {
        self::assertNull(PngStructure::parse(self::png([['iTXt', ReceiptFixtures::itxtData(true)]])));
        self::assertNotNull(PngStructure::parse(self::png([['iTXt', ReceiptFixtures::itxtData(false)]])));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedItxt(): array
    {
        return [
            'empty' => [''],
            'no keyword terminator' => ['Comment'],
            'no flag after the keyword' => ["Comment\x00"],
            'flag that is neither 0 nor 1' => ["Comment\x00\x02\x00\x00\x00"],
            'terminator beyond the keyword limit' => [str_repeat('k', 200) . "\x00\x00\x00\x00\x00"],
        ];
    }

    #[DataProvider('malformedItxt')]
    public function testRefusesAnItxtWhoseCompressionFlagCannotBeRead(string $data): void
    {
        self::assertNull(PngStructure::parse(self::png([['iTXt', $data]])));
    }

    public function testAcceptsOneProfileAndRefusesASecondOne(): void
    {
        $profile = ['iCCP', "p\x00\x00" . (string) gzcompress('x')];

        self::assertNotNull(PngStructure::parse(self::png([$profile])));
        self::assertNull(PngStructure::parse(self::png([$profile, $profile])));
    }

    public function testIhdrMustBeFirstAndOnlyOnceWithThirteenBytes(): void
    {
        $idat = ['IDAT', "\x00"];
        $iend = ['IEND', ''];

        self::assertNull(PngStructure::parse(ReceiptFixtures::pngOfChunks([['tEXt', "a\x00b"], self::ihdr(), $idat, $iend])));
        self::assertNull(PngStructure::parse(ReceiptFixtures::pngOfChunks([self::ihdr(), self::ihdr(), $idat, $iend])));
        self::assertNull(PngStructure::parse(ReceiptFixtures::pngOfChunks([['IHDR', substr(ReceiptFixtures::pngIhdrData(4, 3), 0, 12)], $idat, $iend])));
        self::assertNull(PngStructure::parse(ReceiptFixtures::pngOfChunks([['IHDR', ReceiptFixtures::pngIhdrData(0, 3)], $idat, $iend])));
    }

    public function testRefusesAFileWithoutTheSignatureOrTheIend(): void
    {
        $complete = self::png();

        self::assertNull(PngStructure::parse(''));
        self::assertNull(PngStructure::parse('<svg/>'));
        self::assertNull(PngStructure::parse(substr($complete, 1)));
        self::assertNull(PngStructure::parse(ReceiptFixtures::pngOfChunks([self::ihdr(), ['IDAT', "\x00"]])));
        self::assertNull(PngStructure::parse(ReceiptFixtures::pngOfChunks([self::ihdr()])));
    }

    public function testRefusesEveryCutOfAFileThatEndsBeforeItsIend(): void
    {
        $complete = self::png([['tEXt', "a\x00b"]]);

        for ($length = 0, $end = strlen($complete) - 12; $length < $end; ++$length) {
            self::assertNull(PngStructure::parse(substr($complete, 0, $length)), "a cut at $length bytes was accepted");
        }
    }

    public function testRefusesALengthThatRunsPastTheEndOrHasTheHighBitSet(): void
    {
        $header = "\x89PNG\r\n\x1A\n";

        self::assertNull(PngStructure::parse($header . pack('N', 13) . 'IHDR' . "\x00\x00"));
        self::assertNull(PngStructure::parse($header . pack('N', 0xFFFFFFFF) . 'IHDR' . ReceiptFixtures::pngIhdrData(4, 3)));
        self::assertNull(PngStructure::parse($header . pack('N', 0x80000000) . 'IHDR' . ReceiptFixtures::pngIhdrData(4, 3)));
    }

    public function testRefusesAChunkTypeThatIsNotFourLetters(): void
    {
        self::assertNull(PngStructure::parse(self::png([['ab1d', 'x']])));
        self::assertNull(PngStructure::parse(self::png([["a\x00cd", 'x']])));
    }

    public function testIgnoresWhatFollowsIend(): void
    {
        self::assertNotNull(PngStructure::parse(self::png() . 'trailing bytes, even a zTXt-looking one'));
    }

    public function testAcceptsExactlyTheChunkLimitAndRefusesOneMore(): void
    {
        // IHDR, IDAT and IEND count too (IDAT does not, see below): the limit is on IHDR + the others + IEND.
        $others = PngStructure::MAX_CHUNKS - 2;
        $atLimit = array_fill(0, $others, ['tEXt', "a\x00b"]);

        self::assertNotNull(PngStructure::parse(self::png($atLimit)));
        self::assertNull(PngStructure::parse(self::png([...$atLimit, ['tEXt', "a\x00b"]])));
    }

    public function testDataChunksDoNotCountTowardsTheLimit(): void
    {
        // A real 10 MB PNG written with 8 KiB IDAT chunks has more than 1,000 of them.
        $chunks = [self::ihdr(), ...array_fill(0, 3 * PngStructure::MAX_CHUNKS, ['IDAT', "\x00"]), ['IEND', '']];

        self::assertNotNull(PngStructure::parse(ReceiptFixtures::pngOfChunks($chunks)));
    }
}
