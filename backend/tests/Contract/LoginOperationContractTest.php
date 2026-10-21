<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * What `POST /auth/login` promises (WP11): public, 200 with a session cookie and the user, a 401 that carries the
 * `WWW-Authenticate` challenge (RFC 9110), and the retry headers on 429 and 503.
 */
final class LoginOperationContractTest extends TestCase
{
    private const string METHOD = 'POST';
    private const string TEMPLATE = '/auth/login';

    public function testTheOperationIsPublicAndTakesABody(): void
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
        foreach ([200, 400, 401, 403, 405, 413, 415, 422, 429, 500, 503] as $status) {
            if ($document->response(self::METHOD, self::TEMPLATE, $status) === null) {
                $missing[] = $status;
            }
        }

        self::assertSame([], $missing, 'Undocumented statuses: ' . implode(', ', $missing));
        self::assertNull($document->response(self::METHOD, self::TEMPLATE, 409), 'login has no conflict to report');
    }

    public function testTheSuccessSetsTheSessionCookieAndReturnsAUser(): void
    {
        $response = BundledSpec::document()->response(self::METHOD, self::TEMPLATE, 200);

        self::assertNotNull($response);
        self::assertContains('Set-Cookie', $response->requiredHeaders());
        self::assertSame(['application/json'], $response->mediaTypes());
    }

    public function testTheUnauthorizedAnswerRequiresTheChallengeHeaderAndIsAProblem(): void
    {
        $response = BundledSpec::document()->response(self::METHOD, self::TEMPLATE, 401);

        self::assertNotNull($response);
        self::assertContains('WWW-Authenticate', $response->requiredHeaders());
        self::assertSame(['application/problem+json'], $response->mediaTypes());
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
}
