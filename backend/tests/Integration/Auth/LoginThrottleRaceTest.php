<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\AuthKeys;
use App\Auth\Throttle\ThrottleRule;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\Api\ApiTestCase;
use App\Tests\Support\AuthMarkers;
use App\Tests\Support\LocalAws;
use Psr\Http\Message\ResponseInterface;

/**
 * The end-to-end check of the login throttle through nginx and php-fpm: 25 wrong-password logins for one account,
 * all sent at once, each from its own viewer address (203.0.113.101 to .125, used by no other test), so no address
 * bucket (limit 30, and IPv4 has no network bucket) can trip and only the account bucket decides. Exactly the account
 * limit gets 401, the rest 429 with `Retry-After`; afterwards the stack table shows that the throttle saw 25 distinct
 * clients and counted every attempt, the blocked ones too.
 *
 * This is NOT the proof of atomicity. The php-fpm pool has pm.max_children = 5 and every allowed attempt holds a
 * worker for an Argon2 verification, so only a few requests overlap and the boundary between the 10th and the 11th
 * attempt is hardly contended. The true race proof is ThrottleCounterConcurrencyTest (parallel processes on the real
 * counter).
 *
 * `make test-integration` depends on this test (it writes the `wrongPassword` marker that the Makefile greps in the
 * logs), so it must run as a whole suite, not with --filter.
 */
final class LoginThrottleRaceTest extends ApiTestCase
{
    private const int ATTEMPTS = 25;
    private const int FIRST_HOST = 101;

    public function testConcurrentWrongPasswordsAreCountedExactly(): void
    {
        $email = 'race-' . bin2hex(random_bytes(6)) . '@example.com';
        $wrongPassword = 'wrong-marker-' . bin2hex(random_bytes(12)) . '-Qx7';
        $registered = $this->request('POST', '/auth/register', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => $email, 'password' => 'correct horse staple 42'], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $registered->getStatusCode());

        $body = json_encode(['email' => $email, 'password' => $wrongPassword], JSON_THROW_ON_ERROR);
        $addresses = [];
        $calls = [];
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $addresses[] = sprintf('203.0.113.%d', self::FIRST_HOST + $i);
            $calls[] = ['POST', '/auth/login', [
                'headers' => ['Content-Type' => 'application/json', 'CloudFront-Viewer-Address' => $addresses[$i] . ':' . random_int(1024, 65535)],
                'body' => $body,
            ]];
        }

        $this->clearAddressBuckets($addresses);

        $responses = $this->requestConcurrently($calls, self::ATTEMPTS);

        $statuses = array_count_values(array_map(static fn(ResponseInterface $r): int => $r->getStatusCode(), $responses));
        ksort($statuses);
        $allowed = ThrottleRule::LOGIN_ACCOUNT_LIMIT;
        self::assertSame([401 => $allowed, 429 => self::ATTEMPTS - $allowed], $statuses);
        foreach ($responses as $response) {
            if ($response->getStatusCode() === 429) {
                self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $response->getHeaderLine('Retry-After'));
                $retryAfter = (int) $response->getHeaderLine('Retry-After');
                self::assertGreaterThanOrEqual(1, $retryAfter);
                self::assertLessThanOrEqual(ThrottleRule::LOGIN_WINDOW_SECONDS, $retryAfter);
            } else {
                self::assertFalse($response->hasHeader('Retry-After'), 'a 401 carries no Retry-After');
                self::assertFalse($response->hasHeader('Set-Cookie'), 'a 401 sets no cookie');
            }
        }
        $this->assertEveryClientAndAttemptWasCounted($email, $addresses);
        AuthMarkers::write(['wrongPassword' => $wrongPassword]);
    }

    private static function stackItems(): ItemStore
    {
        $table = getenv('DYNAMODB_TABLE');
        self::assertIsString($table);
        self::assertNotSame('', $table, 'DYNAMODB_TABLE is not set');

        return new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $table);
    }

    /**
     * The stack table lives on between runs (a bucket stays for its 15-minute window, and could even roll over during
     * the race), so the buckets of these test-owned addresses are deleted first and must be exactly 1 afterwards.
     *
     * @param list<string> $addresses
     */
    private function clearAddressBuckets(array $addresses): void
    {
        $items = self::stackItems();
        foreach ($addresses as $address) {
            $items->deleteItem(AuthKeys::throttle(AuthKeys::LOGIN_IP, $address));
        }
    }

    /**
     * @param list<string> $addresses
     */
    private function assertEveryClientAndAttemptWasCounted(string $email, array $addresses): void
    {
        $items = self::stackItems();
        $account = $items->getItem(AuthKeys::throttle(AuthKeys::LOGIN_EMAIL, hash('sha256', $email)));
        self::assertSame(self::ATTEMPTS, $account['attempts'] ?? null, 'the account bucket counted every attempt, the blocked ones too');
        foreach ($addresses as $address) {
            $bucket = $items->getItem(AuthKeys::throttle(AuthKeys::LOGIN_IP, $address));
            self::assertSame(1, $bucket['attempts'] ?? null, "the address bucket of $address counted exactly its own attempt");
        }
    }
}
