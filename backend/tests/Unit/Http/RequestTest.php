<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

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
}
