<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Problem\HttpProblem;
use App\Http\QueryString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryStringTest extends TestCase
{
    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('validQueries')]
    public function testParsesValidQueries(string $raw, array $expected): void
    {
        self::assertSame($expected, QueryString::parse($raw));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function validQueries(): iterable
    {
        yield 'empty' => ['', []];
        yield 'one pair' => ['a=1', ['a' => '1']];
        yield 'two pairs' => ['a=1&b=2', ['a' => '1', 'b' => '2']];
        yield 'empty value' => ['a=', ['a' => '']];
        yield 'key without equals' => ['flag', ['flag' => '']];
        yield 'equals inside value' => ['a=b=c', ['a' => 'b=c']];
        yield 'empty segments are skipped' => ['&a=1&&b=2&', ['a' => '1', 'b' => '2']];
        yield 'only separators' => ['&&', []];
        yield 'plus is a space in a value' => ['q=a+b', ['q' => 'a b']];
        yield 'plus is a space in a key' => ['a+b=1', ['a b' => '1']];
        yield 'encoded plus is a plus' => ['q=a%2Bb', ['q' => 'a+b']];
        yield 'percent decoding' => ['q=%41%62', ['q' => 'Ab']];
        yield 'lower case hex' => ['q=%c3%a9', ['q' => "\u{e9}"]];
        yield 'upper case hex' => ['q=%C3%A9', ['q' => "\u{e9}"]];
        yield 'encoded ampersand and equals' => ['q=a%26b%3Dc', ['q' => 'a&b=c']];
        yield 'encoded slash' => ['q=a%2Fb', ['q' => 'a/b']];
        yield 'encoded percent is not decoded twice' => ['q=%2541', ['q' => '%41']];
        yield 'emoji' => ['q=%F0%9F%92%B8', ['q' => "\u{1F4B8}"]];
        yield 'raw utf-8' => ["q=caf\u{e9}", ['q' => "caf\u{e9}"]];
        yield 'encoded key' => ['a%20b=1', ['a b' => '1']];
        yield 'sql characters' => ["q=%27%20OR%20%271%27%3D%271", ['q' => "' OR '1'='1"]];
        yield 'case sensitive keys' => ['a=1&A=2', ['a' => '1', 'A' => '2']];
        yield 'dot and space in key are kept' => ['a.b=1&c+d=2', ['a.b' => '1', 'c d' => '2']];
        yield 'question mark in value' => ['q=what?', ['q' => 'what?']];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesGive400(string $raw): void
    {
        try {
            QueryString::parse($raw);
            self::fail('Expected an HttpProblem.');
        } catch (HttpProblem $problem) {
            self::assertSame(400, $problem->problem->status);
            self::assertStringNotContainsString($raw, $problem->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'repeated key' => ['a=1&a=2'];
        yield 'repeated key, same value' => ['a=1&a=1'];
        yield 'repeated key without value' => ['a&a'];
        yield 'repeated key through encoding' => ['a=1&%61=2'];
        yield 'repeated key through plus' => ['a+b=1&a%20b=2'];
        yield 'bracket pair' => ['a[]=1'];
        yield 'bracket with name' => ['a[x]=1'];
        yield 'bracket with index' => ['a[0]=1'];
        yield 'lone open bracket' => ['a[=1'];
        yield 'lone close bracket' => ['a]=1'];
        yield 'encoded brackets' => ['a%5B%5D=1'];
        yield 'encoded brackets lower case' => ['a%5b%5d=1'];
        yield 'empty key' => ['=1'];
        yield 'empty key after valid pair' => ['a=1&=2'];
        yield 'percent without digits' => ['a=%'];
        yield 'percent with one digit' => ['a=%4'];
        yield 'percent with non-hex digits' => ['a=%zz'];
        yield 'percent with one non-hex digit' => ['a=%4g'];
        yield 'percent invalid in key' => ['%=1'];
        yield 'percent sign then plus' => ['a=%+1'];
        yield 'non utf-8 after decoding' => ['a=%FF'];
        yield 'truncated utf-8 sequence' => ['a=%C3'];
        yield 'overlong utf-8' => ['a=%C0%AF'];
        yield 'utf-16 surrogate in utf-8' => ['a=%ED%A0%80'];
        yield 'non utf-8 in key' => ['%FF=1'];
        yield 'raw non utf-8 byte' => ["a=\xFF"];
        yield 'encoded null byte' => ['a=%00'];
        yield 'encoded null byte in key' => ['a%00b=1'];
        yield 'raw null byte' => ["a=\0"];
    }

    public function testTooManyPairsGive400(): void
    {
        $raw = implode('&', array_map(static fn(int $i): string => 'k' . $i . '=v', range(1, QueryString::MAX_PAIRS + 1)));

        try {
            QueryString::parse($raw);
            self::fail('Expected an HttpProblem.');
        } catch (HttpProblem $problem) {
            self::assertSame(400, $problem->problem->status);
        }
    }

    public function testExactlyTheMaximumNumberOfPairsIsAccepted(): void
    {
        $raw = implode('&', array_map(static fn(int $i): string => 'k' . $i . '=v', range(1, QueryString::MAX_PAIRS)));

        self::assertCount(QueryString::MAX_PAIRS, QueryString::parse($raw));
    }

    public function testResultValuesAreAlwaysStrings(): void
    {
        $result = QueryString::parse('a=1&b=true&c=&d');

        self::assertSame(['a' => '1', 'b' => 'true', 'c' => '', 'd' => ''], $result);
    }

    public function testPrototypeAndSuperGlobalLikeKeysAreOrdinaryKeys(): void
    {
        self::assertSame(
            ['__proto__' => 'x', 'GLOBALS' => 'y'],
            QueryString::parse('__proto__=x&GLOBALS=y'),
        );
    }
}
