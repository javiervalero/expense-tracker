<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;

/** What `GET /auth/me` and `POST /auth/logout` promise (WP12). */
final class SessionOperationsContractTest extends TestCase
{
    public function testMeNeedsTheSessionCookieAndDocumentsItsStatuses(): void
    {
        $document = BundledSpec::document();

        self::assertContains(['GET', '/auth/me'], $document->operations());
        self::assertSame([['sessionCookie' => []]], $document->security('GET', '/auth/me'));
        foreach ([200, 401, 403, 405, 500, 503] as $status) {
            self::assertNotNull($document->response('GET', '/auth/me', $status), "GET /auth/me $status");
        }
        self::assertFalse($document->hasRequestBody('GET', '/auth/me'));
    }

    public function testMeUnauthorizedRequiresTheChallengeAndIsAProblem(): void
    {
        $response = BundledSpec::document()->response('GET', '/auth/me', 401);

        self::assertNotNull($response);
        self::assertContains('WWW-Authenticate', $response->requiredHeaders());
        self::assertSame(['application/problem+json'], $response->mediaTypes());
    }

    public function testLogoutIsPublicHasNoBodyAndAnswers204WithTheClearingCookie(): void
    {
        $document = BundledSpec::document();

        self::assertContains(['POST', '/auth/logout'], $document->operations());
        self::assertSame([], $document->security('POST', '/auth/logout'));
        self::assertFalse($document->hasRequestBody('POST', '/auth/logout'));
        foreach ([204, 403, 405, 500, 503] as $status) {
            self::assertNotNull($document->response('POST', '/auth/logout', $status), "POST /auth/logout $status");
        }
        $noContent = $document->response('POST', '/auth/logout', 204);
        self::assertNotNull($noContent);
        self::assertContains('Set-Cookie', $noContent->requiredHeaders());
        self::assertSame([], $noContent->mediaTypes());
        self::assertNull($document->response('POST', '/auth/logout', 401), 'logout has no 401: it is always 204');
    }
}
