<?php

declare(strict_types=1);

namespace App\Tests\Conformance\S3;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Browser uploads come from the Vite dev server: after the bootstrap's PutBucketCors, the preflight is allowed for
 * `http://localhost:5173` and refused for any other origin. The CORS rule is set by `local-aws-init`, not by the test,
 * so this also proves the bootstrap did its job.
 */
final class CorsTest extends S3ConformanceCase
{
    private const ALLOWED_ORIGIN = 'http://localhost:5173';
    private const OTHER_ORIGIN = 'http://evil.example';

    /**
     * @return array{status: int, allowOrigin: ?string, allowMethods: list<string>, vary: list<string>}
     */
    private function preflight(string $origin, string $method): array
    {
        $response = $this->http->request('OPTIONS', $this->localAws->s3Endpoint() . '/' . self::RECEIPTS_BUCKET, [
            'headers' => [
                'Origin' => $origin,
                'Access-Control-Request-Method' => $method,
                'Access-Control-Request-Headers' => 'content-type',
            ],
        ]);

        return [
            'status' => $response->getStatusCode(),
            'allowOrigin' => $response->hasHeader('Access-Control-Allow-Origin') ? $response->getHeaderLine('Access-Control-Allow-Origin') : null,
            'allowMethods' => self::tokens($response->getHeaderLine('Access-Control-Allow-Methods')),
            'vary' => self::tokens($response->getHeaderLine('Vary')),
        ];
    }

    /**
     * @return list<string> the comma-separated tokens of a header value, trimmed
     */
    private static function tokens(string $headerValue): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $headerValue)), static fn(string $token): bool => $token !== ''));
    }

    public function testPreflightForTheViteOriginIsAllowedForPost(): void
    {
        $result = $this->preflight(self::ALLOWED_ORIGIN, 'POST');

        self::assertContains($result['status'], [200, 204]);
        self::assertSame(self::ALLOWED_ORIGIN, $result['allowOrigin']);
    }

    public function testPreflightForTheViteOriginIsAllowedForGet(): void
    {
        $result = $this->preflight(self::ALLOWED_ORIGIN, 'GET');

        self::assertContains($result['status'], [200, 204]);
        self::assertSame(self::ALLOWED_ORIGIN, $result['allowOrigin']);
    }

    public function testPreflightForAnotherOriginIsRefused(): void
    {
        $result = $this->preflight(self::OTHER_ORIGIN, 'POST');

        self::assertGreaterThanOrEqual(400, $result['status']);
        self::assertNull($result['allowOrigin'], 'another origin must not get an Access-Control-Allow-Origin header');
    }

    public function testPreflightForADisallowedMethodIsRefused(): void
    {
        $result = $this->preflight(self::ALLOWED_ORIGIN, 'DELETE');

        self::assertGreaterThanOrEqual(400, $result['status']);
        self::assertNull($result['allowOrigin']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nearMissOrigins(): iterable
    {
        yield 'suffix appended to the host' => ['http://localhost:5173.evil.example'];
        yield 'other scheme' => ['https://localhost:5173'];
        yield 'other port' => ['http://localhost:5174'];
    }

    #[DataProvider('nearMissOrigins')]
    public function testPreflightForANearMissOriginIsRefused(string $origin): void
    {
        $result = $this->preflight($origin, 'POST');

        self::assertGreaterThanOrEqual(400, $result['status']);
        self::assertNull($result['allowOrigin'], "{$origin} must not get an Access-Control-Allow-Origin header");
        self::assertSame([], $result['allowMethods']);
    }

    public function testAllowedPreflightListsExactlyTheConfiguredMethods(): void
    {
        $result = $this->preflight(self::ALLOWED_ORIGIN, 'POST');

        $methods = array_map(strtoupper(...), $result['allowMethods']);
        foreach (['POST', 'GET', 'HEAD'] as $method) {
            self::assertContains($method, $methods);
        }
        foreach (['DELETE', 'PUT'] as $method) {
            self::assertNotContains($method, $methods);
        }
    }

    public function testAllowedPreflightVariesOnOrigin(): void
    {
        $result = $this->preflight(self::ALLOWED_ORIGIN, 'POST');

        self::assertContains('Origin', $result['vary'], 'a response that depends on Origin must say so, or a cache may serve it to another origin');
    }
}
