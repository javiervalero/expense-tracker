<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Cookie;

use App\Http\Cookie\CookieHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CookieHeaderTest extends TestCase
{
    public function testParsesTheJoinThatBrefBuilds(): void
    {
        self::assertSame(
            [['name' => 'a', 'value' => '1'], ['name' => 'b', 'value' => '2']],
            CookieHeader::parse('a=1; b=2'),
        );
    }

    public function testParsesAnHttp2StyleMergeWithoutSpacesOrWithTabs(): void
    {
        self::assertSame(
            [['name' => 'a', 'value' => '1'], ['name' => 'b', 'value' => '2'], ['name' => 'c', 'value' => '3']],
            CookieHeader::parse("a=1;b=2;\t c=3 \t"),
        );
    }

    public function testKeepsADuplicateNameTwiceInOrder(): void
    {
        self::assertSame(
            [['name' => 'a', 'value' => '1'], ['name' => 'b', 'value' => '2'], ['name' => 'a', 'value' => '3']],
            CookieHeader::parse('a=1; b=2; a=3'),
        );
    }

    public function testSplitsOnTheFirstEqualsSignOnly(): void
    {
        self::assertSame([['name' => 'a', 'value' => 'x=y==']], CookieHeader::parse('a=x=y=='));
    }

    public function testSkipsAPairWithoutEqualsSign(): void
    {
        self::assertSame([['name' => 'b', 'value' => '2']], CookieHeader::parse('flag; b=2'));
    }

    public function testKeepsAQuotedValueRaw(): void
    {
        self::assertSame([['name' => 'a', 'value' => '"x y"']], CookieHeader::parse('a="x y"'));
    }

    public function testDoesNotUrlDecodeValues(): void
    {
        self::assertSame([['name' => 'a', 'value' => '%41+b']], CookieHeader::parse('a=%41+b'));
    }

    public function testKeepsAnEmptyValue(): void
    {
        self::assertSame([['name' => 'a', 'value' => '']], CookieHeader::parse('a='));
    }

    #[DataProvider('emptyResults')]
    public function testParsesNothingFromAnEmptyOrBlankHeader(string $header): void
    {
        self::assertSame([], CookieHeader::parse($header));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyResults(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces and tabs' => [" \t "];
        yield 'only separators' => [';;; ;'];
    }

    #[DataProvider('invalidNames')]
    public function testSkipsAPairWhoseNameIsNotAToken(string $header): void
    {
        self::assertSame([['name' => 'ok', 'value' => '1']], CookieHeader::parse($header . '; ok=1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty name' => ['=1'];
        yield 'space inside name' => ['a b=1'];
        yield 'space before equals' => ['a =1'];
        yield 'separator character' => ['a(b)=1'];
        yield 'non-ascii' => ["caf\u{e9}=1"];
        yield 'control character' => ["a\x01b=1"];
        yield 'quote' => ['"a"=1'];
    }

    public function testAcceptsEveryTokenCharacterInANameAndIsCaseSensitive(): void
    {
        self::assertSame(
            [['name' => "!#$%&'*+-.^_`|~09azAZ", 'value' => '1'], ['name' => 'A', 'value' => '2']],
            CookieHeader::parse("!#$%&'*+-.^_`|~09azAZ=1; A=2"),
        );
    }

    public function testAHeaderOfExactlyTheLimitIsParsed(): void
    {
        $header = 'a=' . str_repeat('x', 8192 - 2);

        self::assertSame(8192, strlen($header));
        self::assertCount(1, CookieHeader::parse($header));
    }

    public function testAHeaderOverTheLimitParsesAsEmpty(): void
    {
        $header = 'a=' . str_repeat('x', 8192 - 1);

        self::assertSame(8193, strlen($header));
        self::assertSame([], CookieHeader::parse($header));
    }

    public function testASemicolonInsideAQuotedValueSplitsThePairBecauseValuesStayRaw(): void
    {
        self::assertSame(
            [['name' => 'a', 'value' => '"x'], ['name' => 'b', 'value' => 'y"']],
            CookieHeader::parse('a="x; b=y"'),
        );
    }

    public function testEveryParameterThatTakesRawCookieDataIsMarkedSensitive(): void
    {
        foreach (['parse', 'pair'] as $name) {
            $method = new \ReflectionMethod(CookieHeader::class, $name);
            $attributes = $method->getParameters()[0]->getAttributes(\SensitiveParameter::class);
            self::assertCount(1, $attributes, $name);
        }
    }
}
