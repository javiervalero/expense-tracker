<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\Api\ApiTestCase;

/**
 * The nginx-level cases, on the Compose network: what nginx itself answers or sets, outside the contract (a path
 * outside `/api`, the 413 for an oversized body, the `Server` header) and the isolation of php-fpm. They use
 * rawRequest() (request() for the one documented case); the API cases are in {@see HealthApiTest}. Run it through `make test-integration` (the stack must be up).
 *
 * What it does NOT cover: the CloudFront-Viewer-Address fill-in. No route exposes that value, and a test-only route
 * is not allowed in src/ or public/. The map is checked by scripts/tests/nginx-viewer-address.test.sh (nginx alone);
 * the end-to-end check, through ClientIp and the request log, is {@see ClientIpOverNginxTest}.
 */
final class HealthOverNginxTest extends ApiTestCase
{
    public function testNeitherPhpNorNginxAdvertiseTheirVersion(): void
    {
        $responses = [
            '/api/v1/health' => $this->request('GET', '/health'),
            '/api/v1/nope' => $this->rawRequest('GET', '/api/v1/nope'),
            '/elsewhere' => $this->rawRequest('GET', '/elsewhere'),
        ];

        foreach ($responses as $path => $response) {
            self::assertFalse($response->hasHeader('X-Powered-By'), $path);
            self::assertSame('nginx', $response->getHeaderLine('Server'), $path);
        }
    }

    public function testAPathOutsideApiIsAProblemJson404FromNginx(): void
    {
        $response = $this->rawRequest('GET', '/index.php');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], $this->problemBody($response));
    }

    public function testABodyOver65KibIsAProblemJson413FromNginx(): void
    {
        $response = $this->rawRequest('POST', '/api/v1/health', ['body' => str_repeat('a', 65 * 1024 + 1)]);

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
