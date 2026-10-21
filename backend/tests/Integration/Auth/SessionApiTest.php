<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\Session\SessionToken;
use App\Tests\Support\Api\ApiTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * `GET /auth/me` and `POST /auth/logout` end to end (nginx -> php-fpm -> DynamoDB Local), every call through request(),
 * so each response is also checked against the bundled contract. Run through `make test-integration`.
 */
final class SessionApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const CLEARING = '__Host-session=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax';

    private static function uniqueEmail(): string
    {
        return 'sam-' . bin2hex(random_bytes(6)) . '@example.com';
    }

    private function credentials(string $path, string $email): ResponseInterface
    {
        return $this->request('POST', $path, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => $email, 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array{id: string, email: string, cookie: string}
     */
    private function registered(): array
    {
        $email = self::uniqueEmail();
        $response = $this->credentials('/auth/register', $email);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $body = $this->problemBody($response);
        self::assertIsString($body['id'] ?? null);

        return ['id' => $body['id'], 'email' => $email, 'cookie' => $cookie];
    }

    private function me(?string $cookie): ResponseInterface
    {
        $this->withSessionCookie($cookie);

        return $this->request('GET', '/auth/me');
    }

    private function logout(?string $cookie): ResponseInterface
    {
        $this->withSessionCookie($cookie);

        return $this->request('POST', '/auth/logout');
    }

    private static function assertIsClearing(ResponseInterface $response): void
    {
        self::assertSame([self::CLEARING], $response->getHeader('Set-Cookie'));
    }

    private static function assertIsChallenge(ResponseInterface $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['Session'], $response->getHeader('WWW-Authenticate'));
    }

    public function testMeReturnsTheIdAndEmailOfTheRegisteredUser(): void
    {
        $user = $this->registered();

        $response = $this->me($user['cookie']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => $user['id'], 'email' => $user['email']], $this->problemBody($response));
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function testMeShowsEachSessionItsOwnUser(): void
    {
        $first = $this->registered();
        $second = $this->registered();

        self::assertSame($first['id'], $this->problemBody($this->me($first['cookie']))['id']);
        self::assertSame($second['id'], $this->problemBody($this->me($second['cookie']))['id']);
    }

    public function testMeWithoutACookieIs401WithTheChallengeAndNoClearingCookie(): void
    {
        $response = $this->me(null);

        self::assertIsChallenge($response);
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function testMeWithAnUnknownOrMalformedCookieIs401AndClearsIt(): void
    {
        foreach ([SessionToken::generate()->cookieValue(), 'short'] as $value) {
            $response = $this->me($value);

            self::assertIsChallenge($response);
            self::assertIsClearing($response);
        }
    }

    public function testLogoutAnswers204ClearsTheCookieAndEndsTheSession(): void
    {
        $user = $this->registered();

        $logout = $this->logout($user['cookie']);

        self::assertSame(204, $logout->getStatusCode());
        self::assertSame('', (string) $logout->getBody());
        self::assertIsClearing($logout);
        $after = $this->me($user['cookie']);
        self::assertIsChallenge($after);
        self::assertIsClearing($after);
    }

    public function testLogoutIsIdempotentAndGivesNoOracle(): void
    {
        $user = $this->registered();
        $first = $this->logout($user['cookie']);

        $again = $this->logout($user['cookie']);
        $unknown = $this->logout(SessionToken::generate()->cookieValue());
        $malformed = $this->logout('short');
        $none = $this->logout(null);

        foreach ([$again, $unknown, $malformed, $none] as $response) {
            self::assertSame(204, $response->getStatusCode());
            self::assertIsClearing($response);
            self::assertSame($first->getHeader('Set-Cookie'), $response->getHeader('Set-Cookie'));
        }
    }

    public function testLogoutEndsOnlyTheSessionItCarries(): void
    {
        $user = $this->registered();
        $login = $this->credentials('/auth/login', $user['email']);
        $otherCookie = $this->sessionCookieFrom($login);
        self::assertNotNull($otherCookie);

        self::assertSame(204, $this->logout($otherCookie)->getStatusCode());

        self::assertSame(200, $this->me($user['cookie'])->getStatusCode());
    }

    public function testLogoutWithoutRequestedWithIs403AndTheSessionSurvives(): void
    {
        $user = $this->registered();
        $this->withSessionCookie($user['cookie']);
        $this->withoutRequestedWith();

        $response = $this->request('POST', '/auth/logout');

        self::assertSame(403, $response->getStatusCode());
        $this->withSessionCookie($user['cookie']);
        self::assertSame(200, $this->request('GET', '/auth/me')->getStatusCode());
    }

    public function testLogoutFromAForeignOriginIs403AndTheSessionSurvives(): void
    {
        $user = $this->registered();
        $this->withSessionCookie($user['cookie']);
        $this->withBrowserOrigin('https://evil.example');

        self::assertSame(403, $this->request('POST', '/auth/logout')->getStatusCode());

        self::assertSame(200, $this->me($user['cookie'])->getStatusCode());
    }

    public function testGetOnLogoutIsRefusedWithoutChangingTheSession(): void
    {
        $user = $this->registered();
        $this->withSessionCookie($user['cookie']);

        $response = $this->requestAs('POST', '/auth/logout', 'GET', '/api/v1/auth/logout');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame(200, $this->me($user['cookie'])->getStatusCode());
    }

    public function testTwoSessionCookiesAreAnonymous(): void
    {
        $user = $this->registered();
        $other = $this->registered();

        $response = $this->request('GET', '/auth/me', ['headers' => [
            'Cookie' => '__Host-session=' . $user['cookie'] . '; __Host-session=' . $other['cookie'],
        ]]);

        self::assertIsChallenge($response);
        self::assertIsClearing($response);
        $this->withSessionCookie(null);
        self::assertSame(200, $this->me($user['cookie'])->getStatusCode(), 'the duplicate did not end the real session');
    }

    public function testADuplicateCookieOnLogoutEndsNothing(): void
    {
        $user = $this->registered();
        $other = $this->registered();

        $response = $this->request('POST', '/auth/logout', ['headers' => [
            'Cookie' => '__Host-session=' . $user['cookie'] . '; __Host-session=' . $other['cookie'],
        ]]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(200, $this->me($user['cookie'])->getStatusCode());
        self::assertSame(200, $this->me($other['cookie'])->getStatusCode());
    }

    public function testALoginRotatesTheSessionSoTheCookieOfRegisterStopsWorking(): void
    {
        $user = $this->registered();
        $this->withSessionCookie($user['cookie']);
        $login = $this->credentials('/auth/login', $user['email']);
        self::assertSame(200, $login->getStatusCode());
        $fresh = $this->sessionCookieFrom($login);
        self::assertNotNull($fresh);

        $old = $this->me($user['cookie']);

        self::assertIsChallenge($old);
        self::assertSame(200, $this->me($fresh)->getStatusCode());
    }

    public function testMeWithAPostIs405(): void
    {
        $response = $this->requestAs('GET', '/auth/me', 'POST', '/api/v1/auth/me');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame(['GET'], array_map('trim', explode(',', $response->getHeaderLine('Allow'))));
    }
}
