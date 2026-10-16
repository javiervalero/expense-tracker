<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\Api\ApiTestCase;

/**
 * The CSRF check end to end (nginx -> php-fpm). Run it through `make test-integration` (the stack must be up).
 * `POST /health` is a route that exists only for GET and HEAD: 405 means the CSRF check let the request through.
 */
final class CsrfApiTest extends ApiTestCase
{
    public function testAPostWithBothHeadersReachesTheRouterAndGets405(): void
    {
        $response = $this->requestAs('GET', '/health', 'POST', '/api/v1/health');

        self::assertSame(405, $response->getStatusCode());
    }

    public function testAPostWithoutTheRequestedWithHeaderIs403(): void
    {
        $this->withoutRequestedWith();

        $response = $this->requestAs('GET', '/health', 'POST', '/api/v1/health');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('The request failed the cross-site request check.', $this->problemBody($response)['detail']);
        self::assertSame('', $response->getHeaderLine('Allow'));
    }

    public function testAPostFromAForeignOriginIs403(): void
    {
        $this->withBrowserOrigin('https://evil.example');

        $response = $this->requestAs('GET', '/health', 'POST', '/api/v1/health');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('The request failed the cross-site request check.', $this->problemBody($response)['detail']);
    }

    public function testAPostWithoutAnOriginIs403(): void
    {
        $this->withBrowserOrigin(null);

        $response = $this->requestAs('GET', '/health', 'POST', '/api/v1/health');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('The request failed the cross-site request check.', $this->problemBody($response)['detail']);
    }

    public function testAHeaderTheTestSetsItselfWins(): void
    {
        $response = $this->requestAs('GET', '/health', 'POST', '/api/v1/health', ['headers' => ['Origin' => 'null']]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('The request failed the cross-site request check.', $this->problemBody($response)['detail']);
    }

    public function testAGetWithoutTheCsrfHeadersIsNotChecked(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
    }
}
