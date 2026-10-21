<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories;

use App\Categories\CategoryName;
use App\Http\Input\TextViolation;
use PHPUnit\Framework\TestCase;

final class CategoryNameTest extends TestCase
{
    private static function named(string $input): CategoryName
    {
        $name = CategoryName::parse($input);
        self::assertInstanceOf(CategoryName::class, $name);

        return $name;
    }

    public function testItTrimsAndKeepsTheCase(): void
    {
        self::assertSame('Food', self::named('  Food ')->value());
    }

    public function testCaseVariantsShareOneKey(): void
    {
        $key = self::named('Food')->key();

        self::assertSame($key, self::named('FOOD')->key());
        self::assertSame($key, self::named('food')->key());
        self::assertSame($key, self::named('  fOOd ')->key());
    }

    public function testTheKeyIsTheSha256HexOfTheFoldedName(): void
    {
        self::assertSame(hash('sha256', 'food'), self::named('Food')->key());
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', self::named('Food')->key());
    }

    public function testSharpSAndDoubleSFoldToTheSameKey(): void
    {
        self::assertSame(self::named("Stra\u{00DF}e")->key(), self::named('STRASSE')->key());
        self::assertSame(hash('sha256', 'strasse'), self::named("Stra\u{00DF}e")->key());
        self::assertSame("Stra\u{00DF}e", self::named("Stra\u{00DF}e")->value());
    }

    public function testFoldingIsPinnedForSpecialCharacters(): void
    {
        self::assertSame(hash('sha256', "i\u{0307}"), self::named("\u{0130}")->key());
        self::assertSame(hash('sha256', "\u{01C6}"), self::named("\u{01C5}")->key());
    }

    public function testAnInnerSpaceSeparatorCollidesWithASpace(): void
    {
        $key = self::named('Eating out')->key();

        self::assertSame($key, self::named("Eating\u{00A0}out")->key());
        self::assertSame($key, self::named("Eating\u{3000}OUT")->key());
        self::assertNotSame($key, self::named('Eatingout')->key());
        self::assertSame("Eating\u{00A0}out", self::named("Eating\u{00A0}out")->value());
    }

    public function testAllowedInvisibleCharactersAndSpaceRunsDoNotChangeTheKey(): void
    {
        $food = self::named('Food')->key();
        self::assertSame($food, self::named("Food\u{200B}")->key());
        self::assertSame($food, self::named("Fo\u{200D}od")->key());
        self::assertSame($food, self::named("Fo\u{200C}o\u{200E}d\u{200F}")->key());
        self::assertSame($food, self::named("F\u{061C}ood")->key());

        $eating = self::named('Eating out')->key();
        self::assertSame($eating, self::named('Eating  out')->key());
        self::assertSame($eating, self::named("Eating \u{00A0}\u{3000} out")->key());
        self::assertSame("Fo\u{200D}od", self::named("Fo\u{200D}od")->value());
    }

    public function testAZwjEmojiNameAndTheSameEmojiWithoutZwjShareAKeyBecauseZwjIsIgnored(): void
    {
        $joined = "\u{1F468}\u{200D}\u{1F469}";

        self::assertSame(self::named($joined)->key(), self::named("\u{1F468}\u{1F469}")->key());
        self::assertNotSame(self::named($joined)->key(), self::named("\u{1F468}")->key());
    }

    public function testDifferentNamesHaveDifferentKeys(): void
    {
        self::assertNotSame(self::named('Food')->key(), self::named('Foods')->key());
    }

    public function testFiftyCharactersPassAndFiftyOneFail(): void
    {
        self::assertSame(str_repeat('a', 50), self::named(str_repeat('a', 50))->value());
        self::assertSame(TextViolation::TooLong, CategoryName::parse(str_repeat('a', 51)));
    }

    public function testItRefusesEmptyControlAndBidiText(): void
    {
        self::assertSame(TextViolation::Empty, CategoryName::parse(" \u{3000}"));
        self::assertSame(TextViolation::ControlCharacter, CategoryName::parse("a\x1F"));
        self::assertSame(TextViolation::BidiControl, CategoryName::parse("\u{2067}a"));
    }
}
