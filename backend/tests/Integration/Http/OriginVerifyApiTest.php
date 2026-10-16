<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\Api\ApiTestCase;

/**
 * The origin check end to end (nginx -> php-fpm). The 403 answers are validated against the contract like any other.
 * Run it through `make test-integration` (the stack must be up).
 */
final class OriginVerifyApiTest extends ApiTestCase
{
    private const FORBIDDEN = ['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403];

    public function testTheLocalOriginValueIsAccepted(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNoHeaderIsAGeneric403(): void
    {
        $this->withoutOriginHeader();

        $response = $this->request('GET', '/health');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(self::FORBIDDEN, $this->problemBody($response));
    }

    public function testAWrongHeaderIsTheSame403(): void
    {
        $this->withOriginHeader('wrong-origin-value-wrong-origin-value');

        $response = $this->request('GET', '/health');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(self::FORBIDDEN, $this->problemBody($response));
    }

    public function testAnUnknownPathWithoutTheHeaderIs403NotTheDocumented404(): void
    {
        $this->withoutOriginHeader();

        $response = $this->requestAs('GET', '/health', 'GET', '/api/v1/unknown');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(self::FORBIDDEN, $this->problemBody($response));
    }

    public function testAnotherMethodWithoutTheHeaderIs403NotTheDocumented405(): void
    {
        $this->withoutOriginHeader();

        $response = $this->requestAs('GET', '/health', 'POST', '/api/v1/health');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Allow'));
    }

    public function testHeadWithoutTheHeaderIs403WithoutABody(): void
    {
        $this->withoutOriginHeader();

        $response = $this->request('HEAD', '/health');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testADuplicatedHeaderMergedByNginxIsRejected(): void
    {
        $fromEnv = getenv('ORIGIN_VERIFY_LOCAL');
        $secret = is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : 'local-origin-verify-not-a-secret';

        // Guzzle sends two header lines; nginx joins them into "A, A" for php-fpm, which must not match.
        $response = $this->request('GET', '/health', ['headers' => ['X-Origin-Verify' => [$secret, $secret]]]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnEmptyHeaderIs403(): void
    {
        $response = $this->request('GET', '/health', ['headers' => ['X-Origin-Verify' => '']]);

        self::assertSame(403, $response->getStatusCode());
    }
}
