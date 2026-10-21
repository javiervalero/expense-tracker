<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\AuthKeys;
use App\Auth\Password\PasswordHasher;
use App\Auth\User\UserRepository;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\Api\ApiTestCase;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TestPasswordHashers;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * `POST /auth/login` end to end (nginx -> php-fpm -> DynamoDB Local), every call through request(), so each response is
 * also checked against the bundled contract. Each test has its own random viewer address (ApiTestCase) and its own
 * e-mail addresses, so the throttle buckets of one test never count another's calls. The tests that need several
 * client addresses set them with withViewerAddress(). Run through `make test-integration` (the stack must be up).
 */
final class LoginApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const WRONG_PASSWORD = 'not the right password';
    private const COOKIE_PATTERN = '/\A__Host-session=[A-Za-z0-9_-]{43}; Max-Age=604800; Path=\/; Secure; HttpOnly; SameSite=Lax\z/';
    private const ACCOUNT_LIMIT = 10;
    private const ADDRESS_LIMIT = 30;
    private const NETWORK_LIMIT = 100;

    private static function uniqueEmail(string $prefix = 'ana'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6)) . '@example.com';
    }

    /** A random IPv6 address in the documentation range, with its own /48 and /64. */
    private static function otherAddress(): string
    {
        return sprintf('2001:db8:%x:%x::1', random_int(0, 0xFFFF), random_int(0, 0xFFFF));
    }

    private function post(string $path, string $email, string $password): ResponseInterface
    {
        return $this->request('POST', $path, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        ]);
    }

    private function register(string $email): ResponseInterface
    {
        $response = $this->post('/auth/register', $email, self::PASSWORD);
        self::assertSame(201, $response->getStatusCode());

        return $response;
    }

    private function login(string $email, string $password = self::PASSWORD): ResponseInterface
    {
        return $this->post('/auth/login', $email, $password);
    }

    private static function items(): ItemStore
    {
        $table = getenv('DYNAMODB_TABLE');
        self::assertIsString($table);
        self::assertNotSame('', $table);

        return new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $table);
    }

    private static function storedHashOf(string $userId): string
    {
        $hash = self::items()->getItem(AuthKeys::user($userId))['passwordHash'] ?? null;
        self::assertIsString($hash);

        return $hash;
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

    /**
     * Everything a client can see of a response except the clock: status, every header but Date, and the body.
     *
     * @return array{int, array<string, list<string>>, string}
     */
    private static function observable(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            if (strtolower((string) $name) !== 'date') {
                $headers[strtolower((string) $name)] = array_values($values);
            }
        }
        ksort($headers);

        return [$response->getStatusCode(), $headers, (string) $response->getBody()];
    }

    public function testLoginAfterRegisterGivesTheUserAndADifferentCookie(): void
    {
        $email = self::uniqueEmail();
        $registered = $this->register($email);
        $first = $this->sessionCookieFrom($registered);
        self::assertNotNull($first);

        $response = $this->login($email);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(self::userOf($registered), self::userOf($response));
        $second = $this->sessionCookieFrom($response);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression(self::COOKIE_PATTERN, $response->getHeaderLine('Set-Cookie'));
        self::assertStringNotContainsString(self::PASSWORD, (string) $response->getBody());
        $session = self::items()->getItem(AuthKeys::session(hash('sha256', $second)));
        self::assertSame(self::userOf($registered)['id'], $session['userId'] ?? null);
    }

    public function testTheAddressIsTrimmedAndCaseInsensitive(): void
    {
        $email = self::uniqueEmail();
        $this->register($email);

        $response = $this->login("  \t" . strtoupper($email) . ' ');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($email, self::userOf($response)['email']);
    }

    public function testASessionTheRequestCarriedIsEndedAndANewTokenIssued(): void
    {
        $email = self::uniqueEmail();
        $old = $this->sessionCookieFrom($this->register($email));
        self::assertNotNull($old);
        $this->withSessionCookie($old);

        $response = $this->login($email);

        self::assertSame(200, $response->getStatusCode());
        $new = $this->sessionCookieFrom($response);
        self::assertNotNull($new);
        self::assertNotSame($old, $new);
        self::assertNull(self::items()->getItem(AuthKeys::session(hash('sha256', $old))), 'the carried session is deleted');
        self::assertNotNull(self::items()->getItem(AuthKeys::session(hash('sha256', $new))));
    }

    public function testAClientChosenCookieIsNeverAdopted(): void
    {
        $email = self::uniqueEmail();
        $this->register($email);
        $chosen = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->withSessionCookie($chosen);

        $response = $this->login($email);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($chosen, $this->sessionCookieFrom($response));
        self::assertNull(self::items()->getItem(AuthKeys::session(hash('sha256', $chosen))));
    }

    public function testAWrongPasswordIs401WithTheChallengeAndNoCookie(): void
    {
        $email = self::uniqueEmail();
        $this->register($email);

        $response = $this->login($email, self::WRONG_PASSWORD);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('Session', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame('Invalid email or password.', $this->problemBody($response)['detail'] ?? null);
        self::assertStringNotContainsString($email, (string) $response->getBody());
    }

    public function testAnUnknownEmailGetsTheSameResponseAsAWrongPasswordByteForByte(): void
    {
        $known = self::uniqueEmail();
        $this->register($known);

        $wrongPassword = $this->login($known, self::WRONG_PASSWORD);
        $unknownEmail = $this->login(self::uniqueEmail('nobody'), self::WRONG_PASSWORD);

        self::assertSame(401, $unknownEmail->getStatusCode());
        self::assertSame(self::observable($wrongPassword), self::observable($unknownEmail));
    }

    public function testElevenFailuresOnOneAccountFromElevenAddressesBlockTheEleventhEvenWithTheRightPassword(): void
    {
        $email = self::uniqueEmail();
        $this->register($email);

        for ($attempt = 1; $attempt <= self::ACCOUNT_LIMIT; ++$attempt) {
            $this->withViewerAddress(self::otherAddress());
            self::assertSame(401, $this->login($email, self::WRONG_PASSWORD)->getStatusCode(), "failure $attempt");
        }
        $this->withViewerAddress(self::otherAddress());
        $blocked = $this->login($email, self::PASSWORD);

        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('application/problem+json', $blocked->getHeaderLine('Content-Type'));
        $retryAfter = (int) $blocked->getHeaderLine('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(900, $retryAfter);
        self::assertSame([], $blocked->getHeader('Set-Cookie'));
        $this->withViewerAddress(self::otherAddress());
        self::assertSame(429, $this->login($email, self::PASSWORD)->getStatusCode(), 'the account stays blocked for the window');
    }

    public function testThirtyOneFailuresFromOneAddressOnThirtyOneEmailsBlockTheThirtyFirst(): void
    {
        for ($attempt = 1; $attempt <= self::ADDRESS_LIMIT; ++$attempt) {
            self::assertSame(401, $this->login(self::uniqueEmail('nobody'), self::WRONG_PASSWORD)->getStatusCode(), "failure $attempt");
        }

        $blocked = $this->login(self::uniqueEmail('nobody'), self::WRONG_PASSWORD);

        self::assertSame(429, $blocked->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $blocked->getHeaderLine('Retry-After'));
    }

    #[Group('slow')]
    public function testOneHundredAndOneFailuresFromDistinctSixtyFoursInsideOneNetworkBlockTheNext(): void
    {
        $network = sprintf('2001:db8:%x', random_int(0, 0xFFFF));
        for ($attempt = 1; $attempt <= self::NETWORK_LIMIT; ++$attempt) {
            $this->withViewerAddress(sprintf('%s:%x::1', $network, $attempt));
            self::assertSame(401, $this->login(self::uniqueEmail('nobody'), self::WRONG_PASSWORD)->getStatusCode(), "failure $attempt");
        }
        $this->withViewerAddress(sprintf('%s:%x::1', $network, self::NETWORK_LIMIT + 1));

        $blocked = $this->login(self::uniqueEmail('nobody'), self::WRONG_PASSWORD);

        self::assertSame(429, $blocked->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $blocked->getHeaderLine('Retry-After'));
        $this->withViewerAddress(self::otherAddress());
        self::assertSame(401, $this->login(self::uniqueEmail('nobody'), self::WRONG_PASSWORD)->getStatusCode(), 'another network is not affected');
    }

    public function testASuccessResetsTheAccountCountSoFailuresBeforeItDoNotAddUp(): void
    {
        $email = self::uniqueEmail();
        $this->register($email);

        for ($attempt = 1; $attempt < self::ACCOUNT_LIMIT; ++$attempt) {
            self::assertSame(401, $this->login($email, self::WRONG_PASSWORD)->getStatusCode());
        }
        self::assertSame(200, $this->login($email)->getStatusCode(), 'the 10th attempt is still allowed');
        for ($attempt = 1; $attempt < self::ACCOUNT_LIMIT; ++$attempt) {
            self::assertSame(401, $this->login($email, self::WRONG_PASSWORD)->getStatusCode(), "after the reset, failure $attempt");
        }
    }

    public function testASuccessGivesTheAddressAttemptBackSoOnlyFailuresCountAgainstTheAddress(): void
    {
        $email = self::uniqueEmail();
        $this->register($email);

        // 40 successful logins from one address would exceed 30 attempts if successes counted.
        for ($attempt = 1; $attempt <= self::ADDRESS_LIMIT + 5; ++$attempt) {
            self::assertSame(200, $this->login($email)->getStatusCode(), "login $attempt");
        }
    }

    public function testAnOutdatedHashIsUpgradedAfterALogin(): void
    {
        $email = self::uniqueEmail();
        $userId = self::userOf($this->register($email))['id'];
        $users = new UserRepository(self::items());
        $old = TestPasswordHashers::cheap()->hash(self::PASSWORD);
        $users->updatePasswordHash($userId, self::storedHashOf($userId), $old);

        $response = $this->login($email);

        self::assertSame(200, $response->getStatusCode());
        $stored = self::items()->getItem(AuthKeys::user($userId))['passwordHash'] ?? null;
        self::assertIsString($stored);
        self::assertNotSame($old, $stored);
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', $stored);
        self::assertTrue(password_verify(self::PASSWORD, $stored));
        self::assertNotSame(PasswordHasher::DUMMY_HASH, $stored);
        self::assertSame(200, $this->login($email)->getStatusCode(), 'and the new hash works');
    }

    public function testTheHashUpdateOnlyReplacesTheHashItExpects(): void
    {
        $userId = self::userOf($this->register(self::uniqueEmail()))['id'];
        $users = new UserRepository(self::items());
        $original = self::storedHashOf($userId);
        $newer = TestPasswordHashers::cheap()->hash('a newer invented password');
        $stale = TestPasswordHashers::cheap()->hash(self::PASSWORD);
        $users->updatePasswordHash($userId, $original, $newer);

        try {
            $users->updatePasswordHash($userId, $original, $stale);
            self::fail('expected ConditionFailed');
        } catch (ConditionFailed) {
        }

        self::assertSame($newer, self::storedHashOf($userId), 'a stale update never overwrites a newer password');
        $users->updatePasswordHash($userId, $newer, $stale);
        self::assertSame($stale, self::storedHashOf($userId), 'a matching expected hash is replaced');
    }

    public function testAnOverLongPasswordIs422WhetherOrNotTheAddressHasAnAccount(): void
    {
        $known = self::uniqueEmail();
        $this->register($known);
        $long = str_repeat('p', 129);

        $existing = $this->login($known, $long);
        $unknown = $this->login(self::uniqueEmail('nobody'), $long);

        self::assertSame(422, $existing->getStatusCode());
        self::assertSame([['pointer' => '/password', 'detail' => 'The password must have at most 128 characters.']], $this->problemBody($existing)['errors'] ?? null);
        self::assertSame(self::observable($existing), self::observable($unknown));
    }

    public function testAnInvalidEmailIs422WithTheEmailPointer(): void
    {
        $response = $this->login('not-an-email');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([['pointer' => '/email', 'detail' => 'Enter a valid email address.']], $this->problemBody($response)['errors'] ?? null);
        self::assertStringNotContainsString('not-an-email', (string) $response->getBody());
    }

    public function testAMissingPasswordIs422(): void
    {
        $response = $this->request('POST', '/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => self::uniqueEmail()], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([['pointer' => '/password', 'detail' => 'This field is required.']], $this->problemBody($response)['errors'] ?? null);
    }

    public function testAPlainTextBodyIs415(): void
    {
        $response = $this->request('POST', '/auth/login', [
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => json_encode(['email' => self::uniqueEmail(), 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(415, $response->getStatusCode());
    }

    public function testMalformedJsonIs400(): void
    {
        $response = $this->request('POST', '/auth/login', ['headers' => ['Content-Type' => 'application/json'], 'body' => '{"email":']);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testABodyOver64KibIs413FromThePhpApplication(): void
    {
        $body = json_encode(['email' => self::uniqueEmail(), 'password' => str_repeat('p', 65600)], JSON_THROW_ON_ERROR);
        self::assertGreaterThan(65536, strlen($body));
        self::assertLessThan(66560, strlen($body));

        $response = $this->request('POST', '/auth/login', ['headers' => ['Content-Type' => 'application/json'], 'body' => $body]);

        self::assertSame(413, $response->getStatusCode());
    }

    public function testWithoutTheRequestedWithHeaderIs403(): void
    {
        $this->withoutRequestedWith();

        self::assertSame(403, $this->login(self::uniqueEmail())->getStatusCode());
    }

    public function testAnUnknownMethodOnThePathIs405(): void
    {
        $response = $this->requestAs('POST', '/auth/login', 'GET', '/api/v1/auth/login');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }
}
