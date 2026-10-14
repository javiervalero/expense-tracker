<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;

/**
 * The API skeleton end to end on the Compose network: the real `api` (nginx) in front of the real `api-php`
 * (php-fpm) running public/index.php (WP12). Run it through `make test-integration` (the stack must be up).
 *
 * What it does NOT cover: the CloudFront-Viewer-Address fill-in. Nothing in Phase 0 exposes that value, and a
 * test-only route is not allowed in src/ or public/. The map is checked by scripts/tests/nginx-viewer-address.test.sh
 * (nginx alone); the end-to-end check, through ClientIp, comes with Phase 1.
 */
final class HealthOverNginxTest extends TestCase
{
    private const DEFAULT_BASE = 'http://api:8080';

    private HttpClient $http;

    private string $base;

    protected function setUp(): void
    {
        $fromEnv = getenv('API_BASE_URL');
        $this->base = is_string($fromEnv) && $fromEnv !== '' ? rtrim($fromEnv, '/') : self::DEFAULT_BASE;
        $this->http = new HttpClient(['http_errors' => false, 'timeout' => 10]);
    }

    /**
     * @param array{body?: string, headers?: array<string, string>} $options Guzzle request options
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->http->request($method, $this->base . $path, $options);
    }

    /**
     * @return array<mixed>
     */
    private function problemBody(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testHealthIsOkWithAJsonBody(): void
    {
        $response = $this->request('GET', '/api/v1/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testTheQueryStringDoesNotChangeTheRoute(): void
    {
        $response = $this->request('GET', '/api/v1/health?probe=1&x=%3Cscript%3E');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    public function testHeadIsOkWithoutABody(): void
    {
        $response = $this->request('HEAD', '/api/v1/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testNeitherPhpNorNginxAdvertiseTheirVersion(): void
    {
        foreach (['/api/v1/health', '/api/v1/nope', '/elsewhere'] as $path) {
            $response = $this->request('GET', $path);

            self::assertFalse($response->hasHeader('X-Powered-By'), $path);
            self::assertSame('nginx', $response->getHeaderLine('Server'), $path);
        }
    }

    public function testAnUnknownApiPathIsAProblemJson404FromPhp(): void
    {
        $response = $this->request('GET', '/api/v1/unknown');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testAnotherMethodIsAProblemJson405WithAllow(): void
    {
        $response = $this->request('POST', '/api/v1/health');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Method Not Allowed', 'status' => 405],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testAPathOutsideApiIsAProblemJson404FromNginx(): void
    {
        $response = $this->request('GET', '/index.php');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], $this->problemBody($response));
    }

    public function testABodyOver65KibIsAProblemJson413FromNginx(): void
    {
        $response = $this->request('POST', '/api/v1/health', ['body' => str_repeat('a', 65 * 1024 + 1)]);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Content Too Large', 'status' => 413], $this->problemBody($response));
    }

    /**
     * S-M1: FastCGI has no authentication, so whoever can reach php-fpm can run code. api-php sits alone on the
     * internal network `api-internal`; this container (on the default network) must not reach it at all.
     */
    public function testPhpFpmIsNotReachableFromOutsideTheApiNetwork(): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('api-php', 9000, $errno, $errstr, 3);

        if (is_resource($socket)) {
            fclose($socket);
        }
        self::assertFalse($socket, 'api-php:9000 must not accept connections from the default network');
    }
}
