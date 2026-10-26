<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Input;

use App\Http\Input\PlainText;
use App\Http\Input\TextViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlainTextTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function edgeWhitespace(): array
    {
        return [
            'spaces' => ['  Food  '],
            'tabs and newlines' => ["\t\nFood\r\n"],
            'no-break space' => ["\u{00A0}Food\u{00A0}"],
            'ideographic space' => ["\u{3000}Food\u{3000}"],
            'mixed' => [" \u{00A0}\t\u{3000}Food\u{2003} "],
        ];
    }

    #[DataProvider('edgeWhitespace')]
    public function testItTrimsUnicodeWhitespaceAtTheEdges(string $input): void
    {
        self::assertSame('Food', PlainText::parse($input, 50));
    }

    public function testInnerWhitespaceIsKept(): void
    {
        self::assertSame("a\u{00A0}b  c", PlainText::parse("a\u{00A0}b  c", 50));
    }

    public function testEmptyAndWhitespaceOnlyAreEmpty(): void
    {
        self::assertSame(TextViolation::Empty, PlainText::parse('', 50));
        self::assertSame(TextViolation::Empty, PlainText::parse(" \t\u{3000}\u{00A0}", 50));
    }

    public function testExactly200CharactersPassAndThe201stFails(): void
    {
        self::assertSame(str_repeat('a', 200), PlainText::parse(str_repeat('a', 200), 200));
        self::assertSame(TextViolation::TooLong, PlainText::parse(str_repeat('a', 201), 200));
    }

    public function testMultibyteCharactersCountAsOne(): void
    {
        $text = str_repeat("\u{00E9}", 200);

        self::assertSame(400, strlen($text));
        self::assertSame($text, PlainText::parse($text, 200));
        self::assertSame(TextViolation::TooLong, PlainText::parse($text . "\u{00E9}", 200));
    }

    public function testTheLengthIsCountedAfterTrimming(): void
    {
        self::assertSame('abc', PlainText::parse('  abc  ', 3));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function controlCharacters(): array
    {
        return [
            'NUL inside' => ["a\x00b"],
            'US inside' => ["a\x1Fb"],
            'DEL inside' => ["a\x7Fb"],
            'NEL inside' => ["a\u{0085}b"],
            'NUL at the edge' => ["\x00a"],
            'DEL at the edge' => ["a\x7F"],
            'NEL at the edge' => ["\u{0085}a\u{0085}"],
            'newline inside' => ["a\nb"],
        ];
    }

    #[DataProvider('controlCharacters')]
    public function testControlCharactersAreRefused(string $input): void
    {
        self::assertSame(TextViolation::ControlCharacter, PlainText::parse($input, 50));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bidiControls(): array
    {
        return [
            'LRE' => ["a\u{202A}b"],
            'RLO' => ["a\u{202E}b"],
            'LRI' => ["a\u{2066}b"],
            'RLI' => ["a\u{2067}b"],
            'PDI at the edge' => ["a\u{2069}"],
        ];
    }

    #[DataProvider('bidiControls')]
    public function testBidiControlsAreRefused(string $input): void
    {
        self::assertSame(TextViolation::BidiControl, PlainText::parse($input, 50));
    }

    public function testTheCharactersNextToTheBidiRangesAreAllowed(): void
    {
        self::assertSame("a\u{202F}b", PlainText::parse("a\u{202F}b", 50));
        self::assertSame("a\u{2065}b", PlainText::parse("a\u{2065}b", 50));
    }

    public function testZwjEmojiSequencesAndHashAreAllowed(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

        self::assertSame("#1 {$family}", PlainText::parse("#1 {$family}", 50));
    }

    public function testInvalidUtf8IsRefusedWithoutThrowing(): void
    {
        self::assertSame(TextViolation::InvalidEncoding, PlainText::parse("a\xC3\x28", 50));
    }

    public function testU2028AndU2029AreTrimmedAtTheEdgesAndRefusedInside(): void
    {
        self::assertSame('a', PlainText::parse("\u{2028}a\u{2029}", 50));
        self::assertSame(TextViolation::ControlCharacter, PlainText::parse("a\u{2028}b", 50));
        self::assertSame(TextViolation::ControlCharacter, PlainText::parse("a\u{2029}b", 50));
    }

    public function testNulAndNelAtTheEdgesAreNotTrimmedAway(): void
    {
        self::assertSame(TextViolation::ControlCharacter, PlainText::parse("\x00", 50));
        self::assertSame(TextViolation::ControlCharacter, PlainText::parse("\u{0085}", 50));
    }

    public function testControlCharactersWinOverLength(): void
    {
        self::assertSame(TextViolation::ControlCharacter, PlainText::parse("a\x00" . str_repeat('a', 300), 200));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invisibleText(): array
    {
        return [
            'BOM' => ["a\u{FEFF}b"],
            'soft hyphen' => ["a\u{00AD}b"],
            'word joiner' => ["a\u{2060}b"],
            'symmetric swapping' => ["a\u{206A}b"],
            'braille blank' => ["a\u{2800}b"],
            'hangul filler' => ["a\u{3164}b"],
            'hangul choseong filler' => ["a\u{115F}b"],
            'hangul jungseong filler' => ["a\u{1160}b"],
            'half-width hangul filler' => ["a\u{FFA0}b"],
            'language tag' => ["a\u{E0001}b"],
        ];
    }

    #[DataProvider('invisibleText')]
    public function testInvisibleCharactersAreRefused(string $input): void
    {
        self::assertSame(TextViolation::InvisibleCharacter, PlainText::parse($input, 50));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noVisibleCharacter(): array
    {
        return [
            'combining mark' => ["\u{0301}"],
            'marks only' => ["\u{0301}\u{0302}"],
            'ZWJ only' => ["\u{200D}"],
            'ZWNJ and ZWJ' => ["\u{200C}\u{200D}"],
            'zero-width space alone' => ["\u{200B}"],
            'LRM alone' => ["\u{200E}"],
            'RLM alone' => ["\u{200F}"],
            'ALM alone' => ["\u{061C}"],
            'weak marks and ZWSP' => ["\u{200B}\u{200E}\u{200F}"],
            'tags only' => ["\u{E0067}\u{E007F}"],
        ];
    }

    #[DataProvider('noVisibleCharacter')]
    public function testTextWithNothingVisibleIsEmpty(string $input): void
    {
        self::assertSame(TextViolation::Empty, PlainText::parse($input, 50));
    }

    public function testWeakDirectionMarksAndZeroWidthSpaceAreAllowedInsideText(): void
    {
        foreach (["\u{200E}", "\u{200F}", "\u{061C}", "\u{200B}"] as $mark) {
            self::assertSame("a{$mark}b", PlainText::parse("a{$mark}b", 50));
            self::assertSame("Food{$mark}", PlainText::parse("Food{$mark}", 50));
        }
        $thai = "\u{0E2A}\u{0E27}\u{0E31}\u{0E2A}\u{0E14}\u{0E35}\u{200B}\u{0E04}\u{0E23}\u{0E31}\u{0E1A}";
        self::assertSame($thai, PlainText::parse($thai, 50));
    }

    public function testTwoHundredFourByteCharactersWithEdgePaddingStillPass(): void
    {
        $text = str_repeat("\u{1F600}", 200);

        self::assertSame($text, PlainText::parse(str_repeat(' ', 500) . $text . str_repeat(' ', 500), 200));
        self::assertSame(TextViolation::TooLong, PlainText::parse($text . "\u{1F600}", 200));
    }

    public function testAFlagTagSequenceIsAllowed(): void
    {
        $scotland = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";

        self::assertSame($scotland, PlainText::parse($scotland, 50));
    }

    public function testZwnjInsideAScriptWordIsAllowed(): void
    {
        $word = "\u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645}";

        self::assertSame($word, PlainText::parse($word, 50));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hugeInputs(): array
    {
        return [
            'spaces' => [' '],
            'ideographic spaces' => ["\u{3000}"],
            'no-break spaces' => ["\u{00A0}"],
        ];
    }

    #[DataProvider('hugeInputs')]
    public function testAHugeWhitespaceRunIsTooLongAndFast(string $space): void
    {
        $input = 'x' . str_repeat($space, 60000) . 'x';
        $start = hrtime(true);

        $result = PlainText::parse($input, 200);

        self::assertSame(TextViolation::TooLong, $result);
        self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9);
    }

    public function testGenerousEdgePaddingStillFitsTheSlack(): void
    {
        self::assertSame('Food', PlainText::parse(str_repeat(' ', 500) . 'Food' . str_repeat(' ', 500), 50));
    }
}
