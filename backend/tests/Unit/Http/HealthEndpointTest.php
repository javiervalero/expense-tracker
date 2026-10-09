<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\HealthEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HealthEndpointTest extends TestCase
{
    private const HEALTH_PATH = '/api/v1/health';

    public function testGetOnTheHealthPathIsOkWithAJsonBody(): void
    {
        $response = (new HealthEndpoint())->handle('GET', self::HEALTH_PATH);

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame('{"status":"ok"}', $response->body);
    }

    public function testHeadOnTheHealthPathIsOkWithTheSameHeadersAndNoBody(): void
    {
        $endpoint = new HealthEndpoint();

        $head = $endpoint->handle('HEAD', self::HEALTH_PATH);

        self::assertSame(200, $head->status);
        self::assertSame($endpoint->handle('GET', self::HEALTH_PATH)->headers, $head->headers);
        self::assertSame('', $head->body);
    }

    #[DataProvider('everyKindOfResponse')]
    public function testEveryResponseIsNotCachedAndNotSniffed(string $method, string $path): void
    {
        $response = (new HealthEndpoint())->handle($method, $path);

        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function everyKindOfResponse(): iterable
    {
        yield 'ok' => ['GET', self::HEALTH_PATH];
        yield 'method not allowed' => ['POST', self::HEALTH_PATH];
        yield 'not found' => ['GET', '/api/v1/other'];
    }

    #[DataProvider('otherMethods')]
    public function testAnotherMethodOnTheHealthPathIsMethodNotAllowed(string $method): void
    {
        $response = (new HealthEndpoint())->handle($method, self::HEALTH_PATH);

        self::assertSame(405, $response->status);
        self::assertSame('GET, HEAD', $response->headers['Allow']);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Method Not Allowed', 'status' => 405],
            json_decode($response->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherMethods(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'get', '', 'BREW'] as $method) {
            yield $method === '' ? 'empty method' : $method => [$method];
        }
    }

    #[DataProvider('otherPaths')]
    public function testAnyOtherPathIsNotFoundWhateverTheMethod(string $method, string $path): void
    {
        $response = (new HealthEndpoint())->handle($method, $path);

        self::assertSame(404, $response->status);
        self::assertArrayNotHasKey('Allow', $response->headers);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404],
            json_decode($response->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function otherPaths(): iterable
    {
        yield 'root' => ['GET', '/'];
        yield 'empty path' => ['GET', ''];
        yield 'other api path' => ['GET', '/api/v1/expenses'];
        yield 'trailing slash' => ['GET', self::HEALTH_PATH . '/'];
        yield 'other version' => ['GET', '/api/v2/health'];
        yield 'different case' => ['GET', '/API/V1/HEALTH'];
        yield 'double slash' => ['GET', '//api/v1/health'];
        yield 'query string is not part of the path' => ['GET', self::HEALTH_PATH . '?x=1'];
        yield 'encoded slash' => ['GET', '/api%2Fv1/health'];
        yield 'unicode and emoji' => ['POST', "/api/v1/h\u{e9}alth/\u{1F4B8}"];
        yield 'sql characters' => ['DELETE', "/api/v1/health' OR '1'='1"];
        yield 'null byte' => ['GET', self::HEALTH_PATH . "\0"];
    }
}
