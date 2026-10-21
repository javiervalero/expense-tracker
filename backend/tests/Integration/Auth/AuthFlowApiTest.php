<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Tests\Support\Api\ApiTestCase;
use App\Tests\Support\AuthMarkers;
use Psr\Http\Message\ResponseInterface;

/**
 * The whole session life cycle end to end: register, me, logout, me (401), login, me. Every call goes through
 * request(), so each response is checked against the bundled contract. The test also writes the markers that
 * `make test-integration` greps in the api-php log: the password and a session token (neither may be logged) and
 * the user id (the `me` request lines must carry it). It also sends a 422, a 400 and a refused logout (CSRF), so the
 * log checks cover the error paths. Run through `make test-integration`.
 *
 * `make test-integration` depends on this test (the markers it writes are what the Makefile greps in the logs), so it
 * must run as a whole suite, not with --filter.
 */
final class AuthFlowApiTest extends ApiTestCase
{
    private function credentials(string $path, string $email, string $password): ResponseInterface
    {
        return $this->request('POST', $path, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        ]);
    }

    private function me(?string $cookie): ResponseInterface
    {
        $this->withSessionCookie($cookie);

        return $this->request('GET', '/auth/me');
    }

    public function testRegisterMeLogoutMeLoginMe(): void
    {
        $email = 'flow-' . bin2hex(random_bytes(6)) . '@example.com';
        // Unique per run and not a substring of anything the stack logs, so a grep hit can only be a leak.
        $password = 'pw-marker-' . bin2hex(random_bytes(12)) . '-Zq9';

        $registered = $this->credentials('/auth/register', $email, $password);
        self::assertSame(201, $registered->getStatusCode());
        $userId = $this->problemBody($registered)['id'] ?? null;
        self::assertIsString($userId);
        $registeredCookie = $this->sessionCookieFrom($registered);
        self::assertNotNull($registeredCookie);

        $me = $this->me($registeredCookie);
        self::assertSame(200, $me->getStatusCode());
        self::assertSame(['id' => $userId, 'email' => $email], $this->problemBody($me));

        $this->withSessionCookie($registeredCookie);
        $logout = $this->request('POST', '/auth/logout');
        self::assertSame(204, $logout->getStatusCode());

        $afterLogout = $this->me($registeredCookie);
        self::assertSame(401, $afterLogout->getStatusCode());

        $this->withSessionCookie(null);
        $login = $this->credentials('/auth/login', $email, $password);
        self::assertSame(200, $login->getStatusCode());
        self::assertSame($userId, $this->problemBody($login)['id'] ?? null);
        $loginCookie = $this->sessionCookieFrom($login);
        self::assertNotNull($loginCookie);
        self::assertNotSame($registeredCookie, $loginCookie);

        $meAgain = $this->me($loginCookie);
        self::assertSame(200, $meAgain->getStatusCode());
        self::assertSame(['id' => $userId, 'email' => $email], $this->problemBody($meAgain));

        $this->assertErrorPathsDoNotEchoThePassword($password);
        $csrfOrigin = $this->aRefusedLogoutLeavesItsOrigin($loginCookie);

        AuthMarkers::write([
            'password' => $password,
            'sessionToken' => $loginCookie,
            'revokedSessionToken' => $registeredCookie,
            'userId' => $userId,
            'csrfOrigin' => $csrfOrigin,
        ]);
    }

    private function assertErrorPathsDoNotEchoThePassword(string $password): void
    {
        $this->withSessionCookie(null);
        $invalidEmail = $this->credentials('/auth/register', 'not-an-email', $password);
        self::assertSame(422, $invalidEmail->getStatusCode());
        self::assertStringNotContainsString($password, (string) $invalidEmail->getBody());

        $malformed = $this->request('POST', '/auth/register', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"email": "x@example.com", "password": "' . $password . '"',
        ]);
        self::assertSame(400, $malformed->getStatusCode());
        self::assertStringNotContainsString($password, (string) $malformed->getBody());
    }

    /**
     * The CSRF refusal logs the Origin it saw; a marker Origin ties the `csrf check failed` log line to this test.
     */
    private function aRefusedLogoutLeavesItsOrigin(string $cookie): string
    {
        $origin = 'http://csrf-' . bin2hex(random_bytes(8)) . '.invalid';
        $this->withSessionCookie($cookie);
        $this->withBrowserOrigin($origin);
        $this->withoutRequestedWith();

        self::assertSame(403, $this->request('POST', '/auth/logout')->getStatusCode());

        return $origin;
    }

    public function testALogoutWithoutTheAjaxHeaderIsRefusedAndKeepsTheSession(): void
    {
        $email = 'flow-' . bin2hex(random_bytes(6)) . '@example.com';
        $registered = $this->credentials('/auth/register', $email, 'correct horse staple 42');
        $cookie = $this->sessionCookieFrom($registered);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);
        $this->withoutRequestedWith();

        $refused = $this->request('POST', '/auth/logout');

        self::assertSame(403, $refused->getStatusCode());
        self::assertSame(200, $this->me($cookie)->getStatusCode());
    }
}
