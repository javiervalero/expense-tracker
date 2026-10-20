<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * What `POST /auth/register` promises (WP10): it is public (`security: []`), answers 201 with a session cookie, and
 * documents every problem the pipeline can raise for it. The retry headers are required, so the validator fails a real
 * 429 or 503 that forgets `Retry-After`.
 */
final class RegisterOperationContractTest extends TestCase
{
    private const string METHOD = 'POST';
    private const string TEMPLATE = '/auth/register';

    public function testTheOperationIsPublicAndExplicitAboutIt(): void
    {
        $document = BundledSpec::document();

        self::assertContains([self::METHOD, self::TEMPLATE], $document->operations());
        self::assertSame([], $document->security(self::METHOD, self::TEMPLATE));
        self::assertTrue($document->hasRequestBody(self::METHOD, self::TEMPLATE));
    }

    public function testItDocumentsExactlyTheExpectedStatuses(): void
    {
        $document = BundledSpec::document();
        $missing = [];
        foreach ([201, 400, 403, 405, 409, 413, 415, 422, 429, 500, 503] as $status) {
            if ($document->response(self::METHOD, self::TEMPLATE, $status) === null) {
                $missing[] = $status;
            }
        }

        self::assertSame([], $missing, 'Undocumented statuses: ' . implode(', ', $missing));
    }

    public function testTheCreatedResponseSetsTheSessionCookieAndReturnsAUser(): void
    {
        $response = BundledSpec::document()->response(self::METHOD, self::TEMPLATE, 201);

        self::assertNotNull($response);
        self::assertContains('Set-Cookie', $response->requiredHeaders());
        self::assertSame(['application/json'], $response->mediaTypes());
    }

    public function testRateLimitAndOverloadAnswersRequireRetryAfter(): void
    {
        foreach ([429, 503] as $status) {
            $response = BundledSpec::document()->response(self::METHOD, self::TEMPLATE, $status);

            self::assertNotNull($response);
            self::assertContains('Retry-After', $response->requiredHeaders(), "$status needs Retry-After");
            self::assertSame(['application/problem+json'], $response->mediaTypes());
        }
    }

    public function testTheValidationAnswerIsAProblemWithFieldErrors(): void
    {
        $response = BundledSpec::document()->response(self::METHOD, self::TEMPLATE, 422);

        self::assertNotNull($response);
        self::assertSame(['application/problem+json'], $response->mediaTypes());
    }

    public function testUserAndCredentialsRejectUnknownMembers(): void
    {
        $bundle = json_decode((string) file_get_contents(BundledSpec::FILE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($bundle);
        $components = $bundle['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);

        foreach (['User', 'Credentials'] as $name) {
            $schema = $schemas[$name] ?? null;
            self::assertIsArray($schema, "schema $name is missing");
            self::assertFalse($schema['additionalProperties'] ?? null, "$name must set additionalProperties: false");
        }
        $credentials = $schemas['Credentials'];
        self::assertIsArray($credentials);
        self::assertSame(['email', 'password'], $credentials['required'] ?? null);
    }
}
