<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\Contract;

use App\Tests\Support\Contract\OpenApiDocument;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OpenApiDocumentTest extends TestCase
{
    private static function fixture(): OpenApiDocument
    {
        return OpenApiDocument::fromFile(__DIR__ . '/../../../Fixtures/contract/openapi.fixture.json');
    }

    public function testMapsAConcretePathToItsTemplate(): void
    {
        self::assertSame('/expenses/{id}', self::fixture()->pathTemplate('GET', '/expenses/42'));
    }

    public function testPrefersALiteralPathOverATemplate(): void
    {
        self::assertSame('/expenses/summary', self::fixture()->pathTemplate('GET', '/expenses/summary'));
    }

    public function testMethodIsCaseInsensitive(): void
    {
        self::assertSame('/health', self::fixture()->pathTemplate('head', '/health'));
    }

    public function testReturnsNullWhenNoPathMatches(): void
    {
        self::assertNull(self::fixture()->pathTemplate('GET', '/nope'));
        self::assertNull(self::fixture()->pathTemplate('GET', '/expenses/1/extra'));
    }

    public function testReturnsNullWhenThePathExistsButNotForThatMethod(): void
    {
        self::assertNull(self::fixture()->pathTemplate('DELETE', '/expenses/42'));
    }

    public function testFindsADocumentedResponse(): void
    {
        $response = self::fixture()->response('GET', '/expenses/{id}', 200);

        self::assertNotNull($response);
        self::assertSame(['application/json'], $response->mediaTypes());
    }

    public function testFollowsAResponseRefIntoComponents(): void
    {
        $response = self::fixture()->response('GET', '/expenses/{id}', 404);

        self::assertNotNull($response);
        self::assertSame(['Cache-Control'], $response->requiredHeaders());
        self::assertSame(['application/problem+json'], $response->mediaTypes());
    }

    public function testReturnsNullForAnUndocumentedStatus(): void
    {
        self::assertNull(self::fixture()->response('GET', '/expenses/{id}', 500));
    }

    public function testListsOnlyRequiredHeadersResolvingRefs(): void
    {
        $response = self::fixture()->response('GET', '/expenses/{id}', 200);

        self::assertNotNull($response);
        self::assertSame(['Cache-Control'], $response->requiredHeaders());
    }

    public function testAResponseWithoutContentHasNoMediaTypes(): void
    {
        $response = self::fixture()->response('GET', '/expenses/summary', 204);

        self::assertNotNull($response);
        self::assertSame([], $response->mediaTypes());
    }

    public function testRejectsAFileThatIsNotAnOpenApiDocument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenApiDocument::fromFile(__FILE__);
    }

    public function testRejectsAMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenApiDocument::fromFile('/nonexistent/openapi.json');
    }

    public function testIgnoresTheQueryStringAndFragmentWhenMatchingAPath(): void
    {
        self::assertSame('/expenses/{id}', self::fixture()->pathTemplate('GET', '/expenses/42?include=tags&x=1'));
        self::assertSame('/expenses/{id}', self::fixture()->pathTemplate('GET', '/expenses/42#top'));
        self::assertSame('/expenses/summary', self::fixture()->pathTemplate('GET', '/expenses/summary?from=2026-01-01'));
    }

    public function testAnUnparsableDocumentReportsTheParseError(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'oas');
        self::assertIsString($file);
        file_put_contents($file, '{"openapi": ');

        try {
            OpenApiDocument::fromFile($file);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Syntax error', $e->getMessage());
        } finally {
            unlink($file);
        }
    }

    public function testListsEveryOperationAsAnUpperCaseMethodAndTemplate(): void
    {
        self::assertSame(
            [['GET', '/expenses/{id}'], ['GET', '/expenses/summary'], ['HEAD', '/health']],
            self::fixture()->operations(),
        );
    }

    private static function operations(): OpenApiDocument
    {
        return OpenApiDocument::fromFile(__DIR__ . '/../../../Fixtures/contract/openapi.operations.fixture.json');
    }

    public function testSecurityIsAnEmptyListWhenTheOperationOptsOut(): void
    {
        self::assertSame([], self::operations()->security('GET', '/open'));
    }

    public function testSecurityListsTheRequirementsOfAnOperation(): void
    {
        self::assertSame([['sessionCookie' => []]], self::operations()->security('POST', '/things'));
    }

    public function testSecurityIsNullWhenTheOperationSaysNothing(): void
    {
        self::assertNull(self::operations()->security('GET', '/forgot'));
    }

    public function testSecurityIsNullForAnUnknownOperation(): void
    {
        self::assertNull(self::operations()->security('DELETE', '/open'));
    }

    public function testHasRequestBodyTellsOperationsWithAndWithoutOne(): void
    {
        self::assertTrue(self::operations()->hasRequestBody('POST', '/things'));
        self::assertFalse(self::operations()->hasRequestBody('GET', '/open'));
        self::assertFalse(self::operations()->hasRequestBody('DELETE', '/open'));
    }

    public function testHeaderParametersResolveRefsAndSkipOtherLocations(): void
    {
        $parameters = self::operations()->headerParameters('POST', '/things');

        self::assertSame(['x-requested-with', 'x-optional'], array_keys($parameters));
        self::assertTrue($parameters['x-requested-with']->required);
        self::assertSame('XMLHttpRequest', $parameters['x-requested-with']->constant);
        self::assertFalse($parameters['x-optional']->required);
        self::assertNull($parameters['x-optional']->constant);
    }

    public function testHeaderParametersOfAnOperationWithoutAnyAreEmpty(): void
    {
        self::assertSame([], self::operations()->headerParameters('GET', '/open'));
        self::assertSame([], self::operations()->headerParameters('DELETE', '/open'));
    }

    public function testGlobalSecurityIsNullWhenTheDocumentHasNone(): void
    {
        self::assertNull(self::operations()->globalSecurity());
    }
}
