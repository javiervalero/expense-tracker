<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Problem\FieldError;
use App\Http\Problem\ProblemDetails;
use App\Http\Problem\ValidationProblem;
use App\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testJsonSetsStatusContentTypeAndEncodedBody(): void
    {
        $response = Response::json(201, ['id' => 'x', 'tags' => ['a']]);

        self::assertSame(201, $response->status);
        self::assertSame(['Content-Type' => ['application/json']], $response->headers);
        self::assertSame('{"id":"x","tags":["a"]}', $response->body);
    }

    public function testJsonEscapesNonAsciiCharacters(): void
    {
        self::assertSame('{"n":"caf\\u00e9"}', Response::json(200, ['n' => "caf\u{e9}"])->body);
    }

    public function testJsonRejectsUnencodableData(): void
    {
        $this->expectException(\JsonException::class);

        Response::json(200, ['bad' => "\xff"]);
    }

    public function testEmptyHasNoBodyAndNoContentType(): void
    {
        $response = Response::empty(204);

        self::assertSame(204, $response->status);
        self::assertSame([], $response->headers);
        self::assertSame('', $response->body);
    }

    public function testProblemUsesTheProblemContentTypeAndTheProblemStatus(): void
    {
        $response = Response::problem(ProblemDetails::forStatus(404));

        self::assertSame(404, $response->status);
        self::assertSame(['Content-Type' => ['application/problem+json']], $response->headers);
        self::assertSame('{"type":"about:blank","title":"Not Found","status":404}', $response->body);
    }

    public function testProblemWithFieldErrors(): void
    {
        $problem = (new ValidationProblem([new FieldError('/a', 'Bad.')]))->problem;

        $decoded = json_decode(Response::problem($problem)->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([['pointer' => '/a', 'detail' => 'Bad.']], is_array($decoded) ? $decoded['errors'] : null);
    }

    public function testWithHeaderReplacesAnyValuesOfTheSameNameIgnoringCase(): void
    {
        $response = Response::empty(200)->withAddedHeader('Set-Cookie', 'a=1')->withHeader('set-cookie', 'b=2');

        self::assertSame(['set-cookie' => ['b=2']], $response->headers);
    }

    public function testWithAddedHeaderKeepsEveryValue(): void
    {
        $response = Response::empty(200)
            ->withAddedHeader('Set-Cookie', 'a=1')
            ->withAddedHeader('SET-COOKIE', 'b=2');

        self::assertSame(['Set-Cookie' => ['a=1', 'b=2']], $response->headers);
    }

    public function testHeaderValuesLookUpIgnoringCase(): void
    {
        $response = Response::json(200, [])->withAddedHeader('Vary', 'Origin');

        self::assertSame(['application/json'], $response->headerValues('content-type'));
        self::assertSame(['Origin'], $response->headerValues('VARY'));
        self::assertSame([], $response->headerValues('Allow'));
    }

    public function testWithersDoNotChangeTheOriginal(): void
    {
        $original = Response::empty(200);

        $original->withHeader('X-A', '1');
        $original->withBody('x');

        self::assertSame([], $original->headers);
        self::assertSame('', $original->body);
    }

    public function testWithBodyReplacesOnlyTheBody(): void
    {
        $response = Response::json(200, ['a' => 1])->withBody('');

        self::assertSame('', $response->body);
        self::assertSame(200, $response->status);
        self::assertSame(['application/json'], $response->headerValues('Content-Type'));
    }

    #[DataProvider('unsafeHeaders')]
    public function testHeaderInjectionIsRejected(string $name, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::empty(200)->withHeader($name, $value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeHeaders(): iterable
    {
        yield 'newline in value' => ['X-A', "a\r\nSet-Cookie: x=1"];
        yield 'line feed in value' => ['X-A', "a\nb"];
        yield 'null in value' => ['X-A', "a\0b"];
        yield 'empty name' => ['', 'a'];
        yield 'colon in name' => ['X:A', 'a'];
        yield 'space in name' => ['X A', 'a'];
        yield 'newline in name' => ["X-A\r\nX-B", 'a'];
    }

    public function testConstructorRejectsInvalidStatuses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Response(99, [], '');
    }

    public function testConstructorRejectsUnsafeHeaders(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Response(200, ['X-A' => ["a\r\nb"]], '');
    }

    public function testConstructorRejectsHeaderNamesThatDifferOnlyInCase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Response(200, ['Content-Type' => ['a'], 'content-type' => ['b']], '');
    }

    public function testJsonReplacesACallerContentTypeInsteadOfDuplicatingIt(): void
    {
        $response = Response::json(200, [], ['content-type' => ['text/plain'], 'X-A' => ['1']]);

        self::assertSame(['application/json'], $response->headerValues('Content-Type'));
        self::assertSame(['Content-Type' => ['application/json'], 'X-A' => ['1']], $response->headers);
    }

    public function testProblemReplacesACallerContentTypeInsteadOfDuplicatingIt(): void
    {
        $response = Response::problem(ProblemDetails::forStatus(404), ['CONTENT-TYPE' => ['text/plain']]);

        self::assertSame(['application/problem+json'], $response->headerValues('content-type'));
        self::assertCount(1, $response->headers);
    }

    #[DataProvider('controlCharacterValues')]
    public function testHeaderValuesWithControlCharactersAreRejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::empty(200)->withHeader('X-A', $value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function controlCharacterValues(): iterable
    {
        yield 'backspace' => ["a\x08b"];
        yield 'vertical tab' => ["a\x0Bb"];
        yield 'form feed' => ["a\x0Cb"];
        yield 'escape' => ["a\x1Bb"];
        yield 'unit separator' => ["a\x1Fb"];
        yield 'delete' => ["a\x7Fb"];
        yield 'start of heading' => ["\x01"];
    }

    public function testHeaderValuesMayContainTabsSpacesAndUtf8(): void
    {
        $response = Response::empty(200)->withHeader('X-A', "a\tb c \u{e9}");

        self::assertSame(["a\tb c \u{e9}"], $response->headerValues('X-A'));
    }

    public function testProblemSubstitutesInvalidUtf8InsteadOfFailing(): void
    {
        $response = Response::problem(new ProblemDetails(500, 'Error', detail: "bad \xff byte"));

        self::assertSame(500, $response->status);
        $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame("bad \u{fffd} byte", $decoded['detail']);
    }

    public function testProblemStillThrowsWhenTheBodyCannotBeEncodedAtAll(): void
    {
        $this->expectException(\JsonException::class);

        Response::problem(new ProblemDetails(500, 'Error', extensions: ['n' => NAN]));
    }

    public function testHeaderValueListsAreReindexed(): void
    {
        $response = new Response(200, ['X-A' => [3 => 'a', 7 => 'b']], '');

        self::assertSame(['a', 'b'], $response->headers['X-A']);
    }

    public function testNumericHeaderNamesDoNotRaiseTypeErrors(): void
    {
        $response = new Response(200, ['123' => ['x']], '');

        self::assertSame(['x'], $response->headerValues('123'));
        self::assertSame(['x', 'y'], $response->withAddedHeader('123', 'y')->headerValues('123'));
        self::assertSame(['z'], $response->withHeader('123', 'z')->headerValues('123'));
    }
}
