<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Relay;

use LocalAws\Relay\RequestPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestPathTest extends TestCase
{
    #[DataProvider('targets')]
    public function testTakesTheRawPathBeforeTheQueryString(string $requestUri, string $expected): void
    {
        self::assertSame($expected, RequestPath::of($requestUri));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function targets(): iterable
    {
        yield 'root' => ['/', '/'];
        yield 'root with query' => ['/?a=b', '/'];
        yield 'health' => ['/health', '/health'];
        yield 'double slash is not the root' => ['//x', '//x'];
        yield 'double slash with query' => ['//x?y', '//x'];
        yield 'query that looks like a host' => ['/?//evil.example/x', '/'];
        yield 'empty' => ['', '/'];
        yield 'only a query' => ['?a=b', '/'];
        yield 'percent-encoding is kept raw' => ['/%2e', '/%2e'];
    }
}
