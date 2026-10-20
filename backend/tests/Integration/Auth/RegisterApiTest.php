<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\AuthKeys;
use App\Auth\Email;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\Api\ApiTestCase;
use App\Tests\Support\LocalAws;
use Psr\Http\Message\ResponseInterface;

/**
 * `POST /auth/register` end to end (nginx -> php-fpm -> DynamoDB Local), every call through request(), so each response
 * is also checked against the bundled contract. Each test has its own random viewer address (ApiTestCase), so the
 * throttle buckets of one test never count another's calls, and its own e-mail addresses. Run through
 * `make test-integration` (the stack must be up).
 */
final class RegisterApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const COOKIE_PATTERN = '/\A__Host-session=[A-Za-z0-9_-]{43}; Max-Age=604800; Path=\/; Secure; HttpOnly; SameSite=Lax\z/';

    private static function uniqueEmail(string $prefix = 'ana'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6)) . '@example.com';
    }

    private function register(string $email, string $password = self::PASSWORD): ResponseInterface
    {
        return $this->request('POST', '/auth/register', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array{id: string, email: string}
     */
    private static function userOf(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id'] ?? null);
        self::assertIsString($body['email'] ?? null);

        return ['id' => $body['id'], 'email' => $body['email']];
    }

    private static function items(): ItemStore
    {
        $table = getenv('DYNAMODB_TABLE');
        self::assertIsString($table);
        self::assertNotSame('', $table);

        return new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $table);
    }

    public function testItCreatesTheAccountAndSignsTheUserIn(): void
    {
        $email = self::uniqueEmail();

        $response = $this->register(' ' . strtoupper($email) . ' ');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = self::userOf($response);
        self::assertSame($email, $body['email']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $body['id']);
        self::assertStringNotContainsString(self::PASSWORD, (string) $response->getBody());
    }

    public function testTheSessionCookieHasTheExactAttributes(): void
    {
        $response = $this->register(self::uniqueEmail());

        self::assertSame(201, $response->getStatusCode());
        $cookies = $response->getHeader('Set-Cookie');
        self::assertCount(1, $cookies);
        self::assertMatchesRegularExpression(self::COOKIE_PATTERN, $cookies[0]);
        self::assertNotNull($this->sessionCookieFrom($response));
    }

    public function testTheAccountIsStoredWithAnArgon2idHashAndASessionExists(): void
    {
        $email = self::uniqueEmail();
        $response = $this->register($email);
        $token = $this->sessionCookieFrom($response);
        self::assertNotNull($token);
        $body = self::userOf($response);
        $parsed = Email::parse($email);
        self::assertInstanceOf(Email::class, $parsed);

        $user = self::items()->getItem(AuthKeys::user($body['id']));
        $lock = self::items()->getItem(AuthKeys::emailLock($parsed));
        $session = self::items()->getItem(AuthKeys::session(hash('sha256', $token)));

        self::assertNotNull($user);
        $hash = $user['passwordHash'] ?? null;
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(password_verify(self::PASSWORD, $hash));
        self::assertSame($email, $user['email']);
        self::assertSame($body['id'], $lock['userId'] ?? null);
        self::assertSame($body['id'], $session['userId'] ?? null);
        self::assertSame('{"id":"' . $body['id'] . '","email":"' . $email . '"}', (string) $response->getBody());
    }

    public function testTheSameAddressInUpperCaseIs409(): void
    {
        $email = self::uniqueEmail();
        self::assertSame(201, $this->register($email)->getStatusCode());

        $response = $this->register(strtoupper($email));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertStringNotContainsString($email, (string) $response->getBody());
    }

    public function testAShortPasswordIs422WithThePasswordPointer(): void
    {
        $response = $this->register(self::uniqueEmail(), 'too short');

        self::assertSame(422, $response->getStatusCode());
        $problem = $this->problemBody($response);
        self::assertSame([['pointer' => '/password', 'detail' => 'The password must have at least 12 characters.']], $problem['errors'] ?? null);
        self::assertStringNotContainsString('too short', (string) $response->getBody());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function testAnUnexpectedMemberIs422AtItsPointer(): void
    {
        $response = $this->request('POST', '/auth/register', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => self::uniqueEmail(), 'password' => self::PASSWORD, 'admin' => true], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([['pointer' => '/admin', 'detail' => 'This field is not allowed.']], $this->problemBody($response)['errors']);
    }

    public function testAPlainTextBodyIs415(): void
    {
        $response = $this->request('POST', '/auth/register', [
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => json_encode(['email' => self::uniqueEmail(), 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(415, $response->getStatusCode());
    }

    public function testMalformedJsonIs400(): void
    {
        $response = $this->request('POST', '/auth/register', ['headers' => ['Content-Type' => 'application/json'], 'body' => '{"email":']);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testABodyOver64KibIs413FromThePhpApplication(): void
    {
        // Over JsonBody::MAX_BYTES (65536) but under nginx's own 65k limit (66560), so PHP answers.
        $body = json_encode(['email' => self::uniqueEmail(), 'password' => str_repeat('p', 65600)], JSON_THROW_ON_ERROR);
        self::assertGreaterThan(65536, strlen($body));
        self::assertLessThan(66560, strlen($body));

        $response = $this->request('POST', '/auth/register', ['headers' => ['Content-Type' => 'application/json'], 'body' => $body]);

        self::assertSame(413, $response->getStatusCode());
    }

    public function testWithoutTheRequestedWithHeaderIs403(): void
    {
        $this->withoutRequestedWith();

        $response = $this->register(self::uniqueEmail());

        self::assertSame(403, $response->getStatusCode());
    }

    public function testTheSixthRegistrationFromOneAddressIs429WithRetryAfter(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertSame(201, $this->register(self::uniqueEmail())->getStatusCode(), "attempt $attempt");
        }

        $response = $this->register(self::uniqueEmail());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $retryAfter = (int) $response->getHeaderLine('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(3600, $retryAfter);
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function testASessionTheRequestCarriedIsEndedAndANewTokenIssued(): void
    {
        $first = $this->register(self::uniqueEmail('first'));
        $old = $this->sessionCookieFrom($first);
        self::assertNotNull($old);
        $this->withSessionCookie($old);

        $second = $this->register(self::uniqueEmail('second'));

        self::assertSame(201, $second->getStatusCode());
        $new = $this->sessionCookieFrom($second);
        self::assertNotNull($new);
        self::assertNotSame($old, $new);
        self::assertNull(self::items()->getItem(AuthKeys::session(hash('sha256', $old))), 'the carried session is deleted');
        self::assertNotNull(self::items()->getItem(AuthKeys::session(hash('sha256', $new))));
    }

    public function testAnUnknownMethodOnThePathIs405(): void
    {
        $response = $this->requestAs('POST', '/auth/register', 'GET', '/api/v1/auth/register');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function testAnEmptyJsonArrayBodyIs400(): void
    {
        $response = $this->request('POST', '/auth/register', ['headers' => ['Content-Type' => 'application/json'], 'body' => '[]']);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAnInvalidEmailIs422WithTheEmailPointer(): void
    {
        $response = $this->register('not-an-email');

        self::assertSame(422, $response->getStatusCode());
        $errors = $this->problemBody($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertSame([['pointer' => '/email', 'detail' => 'Enter a valid email address.']], $errors);
        self::assertStringNotContainsString('not-an-email', (string) $response->getBody());
    }
}
