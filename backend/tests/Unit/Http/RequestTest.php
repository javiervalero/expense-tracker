<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\QueryString;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testHeaderLookupIgnoresCase(): void
    {
        $request = new Request('GET', '/api/v1/health', [], ['x-request-id' => 'abc'], '');

        self::assertSame('abc', $request->header('X-Request-Id'));
        self::assertSame('abc', $request->header('x-request-id'));
    }

    public function testMissingHeaderIsNull(): void
    {
        self::assertNull((new Request('GET', '/', [], [], ''))->header('Cookie'));
    }

    public function testWithAttributeReturnsACopyAndLeavesTheOriginalUntouched(): void
    {
        $original = new Request('GET', '/', [], [], '');

        $copy = $original->withAttribute('userId', 'u1');

        self::assertNotSame($original, $copy);
        self::assertNull($original->attribute('userId'));
        self::assertSame('u1', $copy->attribute('userId'));
    }

    public function testAttributeFallsBackToTheDefault(): void
    {
        self::assertSame('none', (new Request('GET', '/', [], [], ''))->attribute('x', 'none'));
    }

    public function testWithAttributeKeepsTheOtherAttributesAndOverwritesTheSameName(): void
    {
        $request = (new Request('GET', '/', [], [], ''))
            ->withAttribute('a', 1)
            ->withAttribute('b', 2)
            ->withAttribute('a', 3);

        self::assertSame(3, $request->attribute('a'));
        self::assertSame(2, $request->attribute('b'));
    }

    public function testCarriesItsParts(): void
    {
        $request = new Request('POST', '/api/v1/x', ['q' => '1'], ['content-type' => 'application/json'], '{}');

        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/x', $request->path);
        self::assertSame(['q' => '1'], $request->query);
        self::assertSame('{}', $request->body);
    }

    public function testAnAttributeWhoseValueIsNullIsPresentAndNotReplacedByTheDefault(): void
    {
        $request = (new Request('GET', '/', [], [], ''))->withAttribute('user', null);

        self::assertNull($request->attribute('user', 'fallback'));
    }

    public function testWithAttributesAddsSeveralAttributesAndOverridesExistingOnes(): void
    {
        $original = (new Request('GET', '/', [], [], ''))->withAttribute('a', 1)->withAttribute('b', 2);

        $copy = $original->withAttributes(['b' => 20, 'c' => 30]);

        self::assertNotSame($original, $copy);
        self::assertSame(2, $original->attribute('b'));
        self::assertNull($original->attribute('c'));
        self::assertSame(1, $copy->attribute('a'));
        self::assertSame(20, $copy->attribute('b'));
        self::assertSame(30, $copy->attribute('c'));
    }

    public function testRouteParametersAreAnAttributeUnderASharedKey(): void
    {
        $request = (new Request('GET', '/', [], [], ''))
            ->withAttributes([Request::ROUTE_PARAMS => ['id' => 'e1']]);

        self::assertSame(['id' => 'e1'], $request->attribute(Request::ROUTE_PARAMS));
    }

    public function testNumericQueryAndHeaderNamesDoNotBreakTheAccessors(): void
    {
        $request = new Request('GET', '/', QueryString::parse('1=x&2'), ['123' => 'h'], '');

        self::assertSame('x', $request->query['1']);
        self::assertSame('', $request->query['2']);
        self::assertSame('h', $request->header('123'));
        self::assertNull($request->header('456'));
    }
}
