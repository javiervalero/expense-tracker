<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\Api\ApiTestCase;
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
}
