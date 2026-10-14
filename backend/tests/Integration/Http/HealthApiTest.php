<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\Api\ApiTestCase;

/**
 * The API cases of the health check end to end (nginx -> php-fpm -> the router). Every response is also checked
 * against the contract by {@see ApiTestCase::request()}; the 404 and 405 cases, which match no operation, are
 * validated as the operation that documents them with {@see ApiTestCase::requestAs()}. Run it through `make test-integration` (the stack must be up).
 */
final class HealthApiTest extends ApiTestCase
{
    public function testHealthIsOkWithAJsonBody(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testTheQueryStringDoesNotChangeTheRoute(): void
    {
        $response = $this->request('GET', '/health?probe=1&x=%3Cscript%3E');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    public function testHeadIsOkWithoutABody(): void
    {
        $response = $this->request('HEAD', '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testAnUnknownApiPathIsAProblemJson404FromPhp(): void
    {
        $response = $this->requestAs('GET', '/health', 'GET', '/api/v1/unknown');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], $this->problemBody($response));
    }

    public function testAnUnknownApiPathWithHeadIsA404WithoutABody(): void
    {
        $response = $this->requestAs('HEAD', '/health', 'HEAD', '/api/v1/unknown');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testAnotherMethodIsAProblemJson405WithAllow(): void
    {
        $response = $this->requestAs('GET', '/health', 'POST', '/api/v1/health');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Method Not Allowed', 'status' => 405], $this->problemBody($response));
    }
}
