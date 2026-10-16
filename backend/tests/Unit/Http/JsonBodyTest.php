<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\JsonBody;
use App\Http\Problem\HttpProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonBodyTest extends TestCase
{
    public function testDecodesAnObject(): void
    {
        self::assertSame(
            ['amount' => 12, 'tags' => ['a', 'b'], 'note' => null],
            JsonBody::decode('application/json', '{"amount":12,"tags":["a","b"],"note":null}'),
        );
    }

    public function testAnEmptyObjectIsAnEmptyArray(): void
    {
        self::assertSame([], JsonBody::decode('application/json', '{}'));
    }

    public function testSurroundingWhitespaceIsAllowed(): void
    {
        self::assertSame(['a' => 1], JsonBody::decode('application/json', " \n\t{\"a\":1}\r\n "));
    }

    public function testUnicodeAndEscapedValuesAreDecoded(): void
    {
        self::assertSame(
            ['note' => "caf\u{e9} \u{1F4B8}", 'sql' => "'; DROP TABLE x;--"],
            JsonBody::decode('application/json', '{"note":"café 💸","sql":"\'; DROP TABLE x;--"}'),
        );
    }

    public function testIntegersBeyondSixtyFourBitsStayStrings(): void
    {
        $decoded = JsonBody::decode('application/json', '{"big":123456789012345678901234567890,"small":5}');

        self::assertSame('123456789012345678901234567890', $decoded['big']);
        self::assertSame(5, $decoded['small']);
    }

    public function testDuplicateKeysKeepTheLastValue(): void
    {
        self::assertSame(['a' => 2], JsonBody::decode('application/json', '{"a":1,"a":2}'));
    }

    #[DataProvider('acceptedContentTypes')]
    public function testAcceptedContentTypes(string $contentType): void
    {
        self::assertSame(['a' => 1], JsonBody::decode($contentType, '{"a":1}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedContentTypes(): iterable
    {
        yield 'plain' => ['application/json'];
        yield 'upper case' => ['Application/JSON'];
        yield 'charset' => ['application/json; charset=utf-8'];
        yield 'charset no space' => ['application/json;charset=utf-8'];
        yield 'charset upper case' => ['application/json; charset=UTF-8'];
        yield 'surrounding spaces' => ['  application/json ; charset=utf-8  '];
    }

    #[DataProvider('rejectedContentTypes')]
    public function testRejectedContentTypesGive415(?string $contentType): void
    {
        $this->assertProblem(415, static fn() => JsonBody::decode($contentType, '{"a":1}'));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function rejectedContentTypes(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'text plain' => ['text/plain'];
        yield 'form' => ['application/x-www-form-urlencoded'];
        yield 'problem json' => ['application/problem+json'];
        yield 'json suffix type' => ['application/vnd.api+json'];
        yield 'prefix only' => ['application/jsonx'];
        yield 'other charset' => ['application/json; charset=iso-8859-1'];
        yield 'other parameter' => ['application/json; boundary=x'];
        yield 'two parameters' => ['application/json; charset=utf-8; x=y'];
        yield 'list' => ['application/json, text/plain'];
        yield 'multipart' => ['multipart/form-data; boundary=x'];
        yield 'quoted charset' => ['application/json; charset="utf-8"'];
        yield 'control char' => ["application/json\0"];
    }

    public function testTheContentTypeIsCheckedBeforeTheSize(): void
    {
        $this->assertProblem(415, static fn() => JsonBody::decode('text/plain', str_repeat('x', 70000)));
    }

    public function testABodyOfExactlyTheLimitIsAccepted(): void
    {
        $padding = str_repeat(' ', JsonBody::MAX_BYTES - 7);

        self::assertSame(['a' => 1], JsonBody::decode('application/json', '{"a":1}' . $padding));
    }

    public function testABodyOneByteOverTheLimitGives413(): void
    {
        $padding = str_repeat(' ', JsonBody::MAX_BYTES - 6);

        $this->assertProblem(413, static fn() => JsonBody::decode('application/json', '{"a":1}' . $padding));
    }

    public function testTheLimitIsInBytesNotCharacters(): void
    {
        $body = '{"a":"' . str_repeat("\u{e9}", JsonBody::MAX_BYTES / 2) . '"}';

        $this->assertProblem(413, static fn() => JsonBody::decode('application/json', $body));
    }

    #[DataProvider('malformedBodies')]
    public function testMalformedBodiesGive400(string $body): void
    {
        $this->assertProblem(400, static fn() => JsonBody::decode('application/json', $body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \n"];
        yield 'truncated' => ['{"a":'];
        yield 'trailing comma' => ['{"a":1,}'];
        yield 'single quotes' => ["{'a':1}"];
        yield 'trailing garbage' => ['{"a":1} x'];
        yield 'two documents' => ['{"a":1}{"b":2}'];
        yield 'comment' => ['{"a":1}/* x */'];
        yield 'invalid utf-8' => ["{\"a\":\"\xff\"}"];
        yield 'byte order mark' => ["\xEF\xBB\xBF{\"a\":1}"];
        yield 'lone surrogate escape' => ['{"a":"\ud800"}'];
        yield 'nan' => ['{"a":NaN}'];
        yield 'null byte' => ["{\"a\":1}\0"];
        yield 'number overflowing to infinity' => ['{"a":1e999}'];
        yield 'nested number overflowing to infinity' => ['{"a":[{"b":-1e999}]}'];
    }

    #[DataProvider('nonObjects')]
    public function testValidJsonThatIsNotAnObjectGives400(string $body): void
    {
        $this->assertProblem(400, static fn() => JsonBody::decode('application/json', $body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonObjects(): iterable
    {
        yield 'empty array' => ['[]'];
        yield 'list' => ['[1,2]'];
        yield 'list of objects' => ['[{"a":1}]'];
        yield 'null' => ['null'];
        yield 'true' => ['true'];
        yield 'number' => ['12'];
        yield 'string' => ['"x"'];
        yield 'whitespace then list' => [" \n[{}]"];
    }

    public function testNestingUpToTheDepthLimitIsAccepted(): void
    {
        $body = str_repeat('{"a":', JsonBody::MAX_DEPTH - 1) . '1' . str_repeat('}', JsonBody::MAX_DEPTH - 1);

        self::assertArrayHasKey('a', JsonBody::decode('application/json', $body));
    }

    public function testNestingBeyondTheDepthLimitGives400(): void
    {
        $body = str_repeat('{"a":', JsonBody::MAX_DEPTH) . '1' . str_repeat('}', JsonBody::MAX_DEPTH);

        $this->assertProblem(400, static fn() => JsonBody::decode('application/json', $body));
    }

    public function testDeeplyNestedArraysGive400Too(): void
    {
        $body = '{"a":' . str_repeat('[', 100) . str_repeat(']', 100) . '}';

        $this->assertProblem(400, static fn() => JsonBody::decode('application/json', $body));
    }

    public function testTheProblemDetailNeverEchoesTheInput(): void
    {
        try {
            JsonBody::decode('application/json', '{"secret-value-123":');
            self::fail('Expected an HttpProblem.');
        } catch (HttpProblem $problem) {
            self::assertStringNotContainsString('secret-value-123', $problem->getMessage());
        }
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertProblem(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('Expected an HttpProblem.');
        } catch (HttpProblem $problem) {
            self::assertSame($status, $problem->problem->status);
        }
    }
}
