<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\Api\ApiTestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The guards of {@see ApiTestCase} itself. They all fail before any call is sent, so they need the bundle but not a
 * live answer.
 */
final class ApiTestCaseGuardsTest extends ApiTestCase
{
    public function testRawRequestRefusesADocumentedOperation(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('use request(), not rawRequest()');

        $this->rawRequest('GET', '/api/v1/health?probe=1');
    }

    public function testRequestRefusesAPathThatIsNotRelativeToTheServerUrl(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('adds the /api/v1 prefix itself');

        $this->request('GET', '/api/v1/health');
    }

    public function testRequestRefusesAPathWithoutALeadingSlash(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->request('GET', 'health');
    }

    public function testRequestAsRefusesAPathOutsideTheApiPrefix(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->requestAs('GET', '/health', 'GET', '/elsewhere');
    }

    public function testSessionCookieFromReadsTheValueOfTheSessionCookie(): void
    {
        $response = new Response(201, ['Set-Cookie' => [
            'other=1; Path=/',
            '__Host-session=abc_DEF-123; Max-Age=604800; Path=/; Secure; HttpOnly; SameSite=Lax',
        ]]);

        self::assertSame('abc_DEF-123', $this->sessionCookieFrom($response));
    }

    public function testSessionCookieFromIsNullWithoutASessionCookie(): void
    {
        self::assertNull($this->sessionCookieFrom(new Response(200)));
        self::assertNull($this->sessionCookieFrom(new Response(200, ['Set-Cookie' => 'other=1; Path=/'])));
    }

    public function testSessionCookieFromFailsWhenTheResponseSetsTheCookieTwice(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->sessionCookieFrom(new Response(201, ['Set-Cookie' => ['__Host-session=a; Path=/', '__Host-session=b; Path=/']]));
    }
}
