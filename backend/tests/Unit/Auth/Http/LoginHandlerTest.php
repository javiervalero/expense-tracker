<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Http;

use App\Auth\Http\LoginHandler;
use App\Auth\Password\PasswordHasher;
use App\Auth\Password\PasswordVerifier;
use App\Auth\Password\PasswordPolicy;
use App\Auth\Session\CarriedSession;
use App\Auth\Session\SessionLifetime;
use App\Auth\Throttle\LoginThrottle;
use App\Auth\Throttle\ThrottleRule;
use App\Auth\User\UserRepository;
use App\Http\ClientAddress;
use App\Http\Middleware\ClientIpMiddleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\InMemorySessionStore;
use App\Tests\Support\InMemoryThrottleStore;
use App\Tests\Support\SpyPasswordVerifier;
use App\Tests\Support\TestPasswordHashers;
use Aws\CommandInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The login flow with fakes: an in-memory throttle store and session store, and the real user repository over a
 * scripted DynamoDB (an unused scripted answer left in the queue proves that a step was never reached). The point
 * here is order and mapping: a blocked attempt never reads or verifies anything, a wrong password never calls
 * `succeeded()`, and an unknown email gets exactly the response a wrong password gets.
 */
final class LoginHandlerTest extends TestCase
{
    private const USER_ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const PASSWORD = 'correct horse staple 42';
    private const EMAIL = 'ana@example.com';
    private const ADDRESS_KEY = '2001:db8:1:2';
    private const NETWORK_KEY = '2001:db8:1::/48';
    private const DUMMY_KEY = 'USER#00000000-0000-4000-8000-000000000000';

    private MockHandler $dynamo;

    /** @var list<CommandInterface> */
    private array $commands = [];

    private InMemoryThrottleStore $throttleStore;
    private InMemorySessionStore $sessions;
    private FrozenClock $clock;

    /** @var resource */
    private $logStream;

    protected function setUp(): void
    {
        $this->dynamo = new MockHandler();
        $this->commands = [];
        $this->throttleStore = new InMemoryThrottleStore();
        $this->clock = new FrozenClock();
        $this->sessions = new InMemorySessionStore($this->clock);
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->logStream = $stream;
    }

    private static function cheap(): PasswordHasher
    {
        return TestPasswordHashers::cheap();
    }

    private static function spy(): SpyPasswordVerifier
    {
        return new SpyPasswordVerifier(self::cheap());
    }

    private function handler(?PasswordVerifier $hasher = null): LoginHandler
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;
            $promise = ($this->dynamo)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $client = DynamoDbClientFactory::create($settings, $recording);
        $logger = new JsonLogger($this->logStream);

        return new LoginHandler(
            new LoginThrottle($this->throttleStore, $logger),
            new PasswordPolicy(),
            $hasher ?? self::cheap(),
            new UserRepository(new ItemStore($client, $settings->tableName)),
            $this->sessions,
            new CarriedSession($this->sessions, $logger),
            $logger,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(string $body, array $headers = [], ?ClientAddress $address = null, ?RequestLogContext $context = null): Request
    {
        $attributes = [
            ClientIpMiddleware::ATTRIBUTE => $address ?? ClientAddress::of('2001:db8:1:2::1', self::ADDRESS_KEY),
            Request::REQUEST_ID => 'req-1',
        ];
        if ($context !== null) {
            $attributes[RequestLogContext::ATTRIBUTE] = $context;
        }

        return new Request('POST', '/api/v1/auth/login', [], $headers + ['content-type' => 'application/json'], $body, $attributes);
    }

    private static function credentials(string $email = self::EMAIL, string $password = self::PASSWORD): string
    {
        return json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR);
    }

    /** Queues the e-mail lock and the user item that findByEmail reads; the hash is made with $hashedWith. */
    private function givenAnAccount(?PasswordVerifier $hashedWith = null, string $password = self::PASSWORD, string $email = self::EMAIL): void
    {
        $hash = ($hashedWith ?? self::cheap())->hash($password);
        $this->dynamo->append(new Result(['Item' => ['PK' => ['S' => 'EMAIL#' . $email], 'SK' => ['S' => 'EMAIL'], 'userId' => ['S' => self::USER_ID]]]));
        $this->dynamo->append(new Result(['Item' => [
            'PK' => ['S' => 'USER#' . self::USER_ID],
            'SK' => ['S' => 'PROFILE'],
            'userId' => ['S' => self::USER_ID],
            'email' => ['S' => $email],
            'passwordHash' => ['S' => $hash],
            'createdAt' => ['S' => '2026-10-16T12:00:00Z'],
        ]]));
    }

    /** Queues the answers for an e-mail with no account: no lock, then the dummy read finds nothing. */
    private function givenNoAccount(): void
    {
        $this->dynamo->append(new Result([]));
        $this->dynamo->append(new Result([]));
    }

    private function logged(): string
    {
        rewind($this->logStream);

        return (string) stream_get_contents($this->logStream);
    }

    private function problemOf(Request $request, ?PasswordVerifier $hasher = null): HttpProblem
    {
        try {
            $this->handler($hasher)->handle($request);
        } catch (HttpProblem $problem) {
            return $problem;
        }
        self::fail('expected an HttpProblem');
    }

    /**
     * @return list<array{pointer: string, detail: string}>
     */
    private static function fieldErrors(HttpProblem $problem): array
    {
        $errors = $problem->problem->extensions['errors'] ?? null;
        self::assertIsArray($errors);
        $list = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            self::assertIsString($error['pointer'] ?? null);
            self::assertIsString($error['detail'] ?? null);
            $list[] = ['pointer' => $error['pointer'], 'detail' => $error['detail']];
        }

        return $list;
    }

    /** What the error middleware turns the problem into, as bytes: status, headers and body. */
    private static function rendered(HttpProblem $problem): string
    {
        $response = Response::problem($problem->problem, $problem->headers);

        return json_encode([$response->status, $response->headers, $response->body], JSON_THROW_ON_ERROR);
    }

    /** @return list<string> the names of the commands sent to DynamoDB, in order */
    private function commandNames(): array
    {
        return array_map(static fn(CommandInterface $command): string => $command->getName(), $this->commands);
    }

    /** @return list<string> the throttle store calls that give attempts back (refund or delete) */
    private function cleanUpCalls(): array
    {
        return array_values(array_filter($this->throttleStore->calls, static fn(string $call): bool => !str_starts_with($call, 'hit|')));
    }

    /** The partition key of the GetItem or UpdateItem sent as command number $index. */
    private function sentKey(int $index): string
    {
        $key = $this->commands[$index]['Key'] ?? null;
        self::assertIsArray($key);
        self::assertIsArray($key['PK'] ?? null);
        self::assertIsString($key['PK']['S'] ?? null);

        return $key['PK']['S'];
    }

    /** The new hash of the UpdateItem sent as command number $index. */
    private function sentPasswordHash(int $index): ?string
    {
        $values = $this->commands[$index]['ExpressionAttributeValues'] ?? null;
        self::assertIsArray($values);
        self::assertIsArray($values[':passwordHash'] ?? null);

        return is_string($values[':passwordHash']['S'] ?? null) ? $values[':passwordHash']['S'] : null;
    }

    /** The hash the UpdateItem sent as command number $index expects to replace. */
    private function sentExpectedHash(int $index): ?string
    {
        $values = $this->commands[$index]['ExpressionAttributeValues'] ?? null;
        self::assertIsArray($values);
        self::assertIsArray($values[':expected'] ?? null);

        return is_string($values[':expected']['S'] ?? null) ? $values[':expected']['S'] : null;
    }

    private function accountKey(): string
    {
        return hash('sha256', self::EMAIL);
    }

    // --- success -------------------------------------------------------------------------------------------------

    public function testTheRightPasswordGives200WithTheUserAndAFreshSessionCookie(): void
    {
        $this->givenAnAccount();

        $response = $this->handler()->handle(self::request(self::credentials()));

        self::assertSame(200, $response->status);
        self::assertSame(['application/json'], $response->headerValues('Content-Type'));
        self::assertSame('{"id":"' . self::USER_ID . '","email":"' . self::EMAIL . '"}', $response->body);
        $cookies = $response->headerValues('Set-Cookie');
        self::assertCount(1, $cookies);
        self::assertMatchesRegularExpression(
            '/\A__Host-session=[A-Za-z0-9_-]{43}; Max-Age=' . SessionLifetime::ABSOLUTE . '; Path=\/; Secure; HttpOnly; SameSite=Lax\z/',
            $cookies[0],
        );
        self::assertCount(1, $this->sessions->sessions);
        self::assertSame(self::USER_ID, array_values($this->sessions->sessions)[0]->userId);
        self::assertSame(['GetItem', 'GetItem'], $this->commandNames());
    }

    public function testTheEmailIsTrimmedAndLowerCasedBeforeTheLookupAndTheThrottle(): void
    {
        $this->givenAnAccount();

        $this->handler()->handle(self::request(self::credentials("  ANA@Example.COM\t")));

        self::assertSame('EMAIL#ana@example.com', $this->sentKey(0));
        self::assertSame(0, $this->throttleStore->count('LOGIN-EMAIL', $this->accountKey()), 'reset after the success');
        self::assertContains('hit|LOGIN-EMAIL|' . $this->accountKey(), $this->throttleStore->calls);
    }

    public function testASuccessIsLoggedWithTheUserIdAndNeverTheEmailPasswordOrToken(): void
    {
        $this->givenAnAccount();
        $context = new RequestLogContext();

        $response = $this->handler()->handle(self::request(self::credentials(), context: $context));

        $log = $this->logged();
        self::assertStringContainsString('login succeeded', $log);
        self::assertStringContainsString(self::USER_ID, $log);
        self::assertStringNotContainsString(self::EMAIL, $log);
        self::assertStringNotContainsString(self::PASSWORD, $log);
        self::assertStringNotContainsString('$argon2id$', $log);
        self::assertStringNotContainsString('__Host-session', $log);
        self::assertStringNotContainsString(substr($response->headerValues('Set-Cookie')[0], 15, 43), $log);
        self::assertSame(self::USER_ID, $context->userId());
    }

    public function testASuccessResetsTheAccountBucketAndRefundsTheAddressBuckets(): void
    {
        $this->givenAnAccount();
        $this->throttleStore->seed('LOGIN-EMAIL', $this->accountKey(), 4);
        $this->throttleStore->seed('LOGIN-IP', self::ADDRESS_KEY, 7);
        $this->throttleStore->seed('LOGIN-NET', self::NETWORK_KEY, 9);

        $this->handler()->handle(self::request(self::credentials()));

        self::assertSame([
            'delete|LOGIN-EMAIL|' . $this->accountKey(),
            'refund|LOGIN-IP|' . self::ADDRESS_KEY,
            'refund|LOGIN-NET|' . self::NETWORK_KEY,
        ], $this->cleanUpCalls());
        self::assertSame(0, $this->throttleStore->count('LOGIN-EMAIL', $this->accountKey()));
        self::assertSame(7, $this->throttleStore->count('LOGIN-IP', self::ADDRESS_KEY), 'counted +1 then refunded -1');
        self::assertSame(9, $this->throttleStore->count('LOGIN-NET', self::NETWORK_KEY));
    }

    public function testASuccessIssuesTheNewSessionFirstAndThenEndsTheCarriedOne(): void
    {
        $this->givenAnAccount();
        $old = $this->sessions->seed('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');
        $this->sessions->operations = [];

        $response = $this->handler()->handle(self::request(self::credentials(), ['cookie' => '__Host-session=' . $old->cookieValue()]));

        self::assertSame(['create', 'delete'], $this->sessions->operations);
        self::assertArrayNotHasKey($old->hash(), $this->sessions->sessions, 'the carried session is gone');
        self::assertCount(1, $this->sessions->sessions);
        self::assertStringNotContainsString($old->cookieValue(), $response->headerValues('Set-Cookie')[0]);
    }

    public function testAFailureToEndTheCarriedSessionIsLoggedAndTheUserIsStillSignedIn(): void
    {
        $this->givenAnAccount();
        $old = $this->sessions->seed('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');
        $this->sessions->deleteFailure = new StorageFailure('DeleteItem', 'InternalServerError');

        $response = $this->handler()->handle(self::request(self::credentials(), ['cookie' => '__Host-session=' . $old->cookieValue()]));

        self::assertSame(200, $response->status);
        self::assertCount(1, $response->headerValues('Set-Cookie'));
        self::assertStringContainsString('could not end the carried session', $this->logged());
        self::assertStringContainsString('InternalServerError', $this->logged());
        self::assertStringNotContainsString($old->cookieValue(), $this->logged());
    }

    public function testADuplicateSessionCookieDeletesNothingAndIsLogged(): void
    {
        $this->givenAnAccount();
        $one = $this->sessions->seed('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');
        $two = $this->sessions->seed('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');
        $this->sessions->operations = [];

        $response = $this->handler()->handle(self::request(
            self::credentials(),
            ['cookie' => '__Host-session=' . $one->cookieValue() . '; __Host-session=' . $two->cookieValue()],
        ));

        self::assertSame(200, $response->status);
        self::assertSame(['create'], $this->sessions->operations);
        self::assertStringContainsString('duplicate session cookie', $this->logged());
    }

    public function testAShortOrOddPasswordStillLogsInBecauseLoginOnlyEnforcesTheMaximum(): void
    {
        foreach (['short', "tab\tinside"] as $legacy) {
            $this->givenAnAccount(password: $legacy);

            $response = $this->handler()->handle(self::request(self::credentials(password: $legacy)));

            self::assertSame(200, $response->status, $legacy);
        }
    }

    public function testAPasswordOfExactly128CharactersIsVerified(): void
    {
        $password = str_repeat('é', 128);
        $this->givenAnAccount(password: $password);

        $response = $this->handler()->handle(self::request(self::credentials(password: $password)));

        self::assertSame(200, $response->status);
    }

    // --- rehash --------------------------------------------------------------------------------------------------

    public function testAnOutdatedHashIsReplacedAfterTheLogin(): void
    {
        $this->givenAnAccount(TestPasswordHashers::cheap());
        $this->dynamo->append(new Result(['Attributes' => []]));

        $response = $this->handler(TestPasswordHashers::upgrading())->handle(self::request(self::credentials()));

        self::assertSame(200, $response->status);
        self::assertSame(['GetItem', 'GetItem', 'UpdateItem'], $this->commandNames());
        self::assertSame('USER#' . self::USER_ID, $this->sentKey(2));
        $stored = $this->sentPasswordHash(2);
        self::assertIsString($stored);
        self::assertStringStartsWith('$argon2id$v=19$m=16,t=1,p=1$', $stored);
        self::assertTrue(password_verify(self::PASSWORD, $stored));
        self::assertNotSame(PasswordHasher::DUMMY_HASH, $stored);
        $condition = $this->commands[2]['ConditionExpression'] ?? null;
        self::assertSame('attribute_exists(PK) AND #passwordHash = :expected', $condition);
        self::assertNotSame($stored, $this->sentExpectedHash(2), 'the update is conditional on the hash it replaces');
        self::assertStringStartsWith('$argon2id$v=19$m=8,t=1,p=1$', (string) $this->sentExpectedHash(2));
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function failedUpdates(): iterable
    {
        yield 'storage error' => [new DynamoDbException('boom', new \Aws\Command('UpdateItem'), ['code' => 'InternalServerError'])];
        yield 'user vanished' => [new DynamoDbException('gone', new \Aws\Command('UpdateItem'), ['code' => 'ConditionalCheckFailedException'])];
        yield 'validation error' => [new DynamoDbException('bad', new \Aws\Command('UpdateItem'), ['code' => 'ValidationException'])];
    }

    #[DataProvider('failedUpdates')]
    public function testAFailedHashUpdateStillLogsTheUserInAndIsLoggedWithoutTheHash(\Throwable $failure): void
    {
        $this->givenAnAccount(TestPasswordHashers::cheap());
        $this->dynamo->append($failure);

        $response = $this->handler(TestPasswordHashers::upgrading())->handle(self::request(self::credentials()));

        self::assertSame(200, $response->status);
        self::assertCount(1, $response->headerValues('Set-Cookie'));
        self::assertCount(1, $this->sessions->sessions);
        $log = $this->logged();
        self::assertStringContainsString('could not upgrade the password hash', $log);
        self::assertStringContainsString(self::USER_ID, $log);
        self::assertStringNotContainsString('$argon2id$', $log);
        self::assertStringNotContainsString(self::PASSWORD, $log);
    }

    public function testAHashingFailureAfterTheLoginIsLoggedWithTheClassOnlyAndTheUserIsStillSignedIn(): void
    {
        $this->givenAnAccount(TestPasswordHashers::cheap());
        $spy = new SpyPasswordVerifier(TestPasswordHashers::upgrading());
        $spy->hashFailure = new \ValueError('Memory cost for Argon2 ' . self::PASSWORD . ' is outside of allowed memory range');

        $response = $this->handler($spy)->handle(self::request(self::credentials()));

        self::assertSame(200, $response->status);
        self::assertCount(1, $response->headerValues('Set-Cookie'));
        self::assertSame(['GetItem', 'GetItem'], $this->commandNames(), 'nothing was written');
        $log = $this->logged();
        self::assertStringContainsString('could not upgrade the password hash', $log);
        self::assertStringContainsString('ValueError', $log);
        self::assertStringContainsString(self::USER_ID, $log);
        self::assertStringNotContainsString('Memory cost', $log, 'the exception message is not logged');
        self::assertStringNotContainsString(self::PASSWORD, $log);
    }

    public function testOnlyAValueErrorIsCaughtAroundTheHashUpgrade(): void
    {
        $this->givenAnAccount(TestPasswordHashers::cheap());
        $spy = new SpyPasswordVerifier(TestPasswordHashers::upgrading());
        $spy->hashFailure = new \LogicException('a bug');

        $this->expectException(\LogicException::class);

        $this->handler($spy)->handle(self::request(self::credentials()));
    }

    public function testACurrentHashIsNotRewritten(): void
    {
        $this->givenAnAccount();

        $this->handler()->handle(self::request(self::credentials()));

        self::assertSame(['GetItem', 'GetItem'], $this->commandNames());
    }

    public function testAWrongPasswordNeverRewritesTheHash(): void
    {
        $this->givenAnAccount(TestPasswordHashers::cheap());

        $this->problemOf(self::request(self::credentials(password: 'not the password')), TestPasswordHashers::upgrading());

        self::assertSame(['GetItem', 'GetItem'], $this->commandNames());
    }

    // --- failures: one answer for an unknown email and a wrong password -------------------------------------------

    public function testAWrongPasswordIs401WithTheChallengeAndTheGenericMessage(): void
    {
        $this->givenAnAccount();

        $problem = $this->problemOf(self::request(self::credentials(password: 'not the password')));

        self::assertSame(401, $problem->problem->status);
        self::assertSame('Invalid email or password.', $problem->problem->detail);
        self::assertSame(['WWW-Authenticate' => ['Session']], $problem->headers);
        self::assertSame([], $this->sessions->sessions);
    }

    public function testAnUnknownEmailGetsExactlyTheResponseAWrongPasswordGets(): void
    {
        $this->givenAnAccount();
        $wrongPassword = $this->problemOf(self::request(self::credentials(password: 'not the password')));
        $this->givenNoAccount();

        $unknownEmail = $this->problemOf(self::request(self::credentials('nobody@example.com', 'not the password')));

        self::assertEquals($wrongPassword->problem, $unknownEmail->problem);
        self::assertSame($wrongPassword->headers, $unknownEmail->headers);
        self::assertSame($wrongPassword->getMessage(), $unknownEmail->getMessage());
        self::assertSame(self::rendered($wrongPassword), self::rendered($unknownEmail));
    }

    public function testAnUnknownEmailReadsTheLockThenTheFixedDummyUserAndWritesNothing(): void
    {
        $this->givenNoAccount();

        $problem = $this->problemOf(self::request(self::credentials('nobody@example.com')));

        self::assertSame(401, $problem->problem->status);
        self::assertSame(['GetItem', 'GetItem'], $this->commandNames(), 'one read more than nothing, as many as a known email');
        self::assertSame('EMAIL#nobody@example.com', $this->sentKey(0));
        self::assertSame(self::DUMMY_KEY, $this->sentKey(1));
        self::assertSame($this->commands[0]['ConsistentRead'] ?? null, $this->commands[1]['ConsistentRead'] ?? 'missing');
        self::assertSame([], $this->sessions->sessions);
    }

    public function testAnUnknownEmailRunsTheDummyVerificationOnceAndNeverVerifiesARealHash(): void
    {
        $this->givenNoAccount();
        $spy = self::spy();

        $this->problemOf(self::request(self::credentials('nobody@example.com')), $spy);

        self::assertSame(1, $spy->count('verifyDummy'));
        self::assertSame(0, $spy->count('verify'));
        self::assertSame(0, $spy->count('hash'));
        self::assertSame(0, $spy->count('needsRehash'));
    }

    public function testAKnownEmailVerifiesTheRealHashOnceAndNeverRunsTheDummy(): void
    {
        $this->givenAnAccount();
        $spy = self::spy();

        $this->problemOf(self::request(self::credentials(password: 'not the password')), $spy);

        self::assertSame(1, $spy->count('verify'));
        self::assertSame(0, $spy->count('verifyDummy'));
        self::assertSame(0, $spy->count('hash'));
    }

    public function testABlockedAttemptRunsNeitherTheRealNorTheDummyVerification(): void
    {
        $this->throttleStore->seed('LOGIN-EMAIL', $this->accountKey(), ThrottleRule::LOGIN_ACCOUNT_LIMIT);
        $spy = self::spy();

        $this->problemOf(self::request(self::credentials()), $spy);

        self::assertSame([], $spy->calls);
    }

    public function testInputThatIsRefusedBeforeTheThrottleRunsNoHashWork(): void
    {
        $spy = self::spy();

        $this->problemOf(self::request(self::credentials('not-an-email', str_repeat('p', 129))), $spy);

        self::assertSame([], $spy->calls);
    }

    public function testUnknownEmailAndWrongPasswordAreLoggedWithAReasonAndNeverTheEmail(): void
    {
        $this->givenAnAccount();
        $this->problemOf(self::request(self::credentials(password: 'not the password')));
        $this->givenNoAccount();
        $this->problemOf(self::request(self::credentials('nobody@example.com', 'not the password')));

        $lines = array_values(array_filter(explode("\n", $this->logged()), static fn(string $line): bool => $line !== ''));
        self::assertCount(2, $lines);
        $known = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $unknown = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($known);
        self::assertIsArray($unknown);
        self::assertSame('login failed', $known['message'] ?? null);
        self::assertSame('wrong_password', $known['reason'] ?? null);
        self::assertSame(self::USER_ID, $known['userId'] ?? null);
        self::assertSame('login failed', $unknown['message'] ?? null);
        self::assertSame('unknown_email', $unknown['reason'] ?? null);
        self::assertArrayNotHasKey('userId', $unknown);
        self::assertStringNotContainsString('example.com', $this->logged());
        self::assertStringNotContainsString('not the password', $this->logged());
    }

    public function testAWrongPasswordNeverCallsSucceededSoNothingIsDeletedOrRefunded(): void
    {
        $this->givenAnAccount();

        $this->problemOf(self::request(self::credentials(password: 'not the password')));

        self::assertSame([], $this->cleanUpCalls());
        self::assertSame(1, $this->throttleStore->count('LOGIN-EMAIL', $this->accountKey()), 'the failure stays counted');
        self::assertSame(1, $this->throttleStore->count('LOGIN-IP', self::ADDRESS_KEY));
        self::assertSame(1, $this->throttleStore->count('LOGIN-NET', self::NETWORK_KEY));
    }

    public function testAnUnknownEmailNeverCallsSucceededAndCountsLikeARealOne(): void
    {
        $this->givenNoAccount();

        $this->problemOf(self::request(self::credentials()));

        self::assertSame([], $this->cleanUpCalls());
        self::assertSame(1, $this->throttleStore->count('LOGIN-EMAIL', $this->accountKey()));
    }

    public function testSucceededRunsOnlyAfterThePasswordIsVerified(): void
    {
        $this->givenAnAccount();
        $this->throttleStore->failures['delete|LOGIN-EMAIL'] = new StorageFailure('DeleteItem', 'InternalServerError');

        $wrong = $this->problemOf(self::request(self::credentials(password: 'not the password')));

        self::assertSame(401, $wrong->problem->status);
        self::assertSame([], $this->cleanUpCalls(), 'no clean-up was even attempted');
    }

    public function testACleanUpFailureAfterACorrectPasswordNeverFailsTheLogin(): void
    {
        $this->givenAnAccount();
        $this->throttleStore->failures['delete|LOGIN-EMAIL'] = new StorageFailure('DeleteItem', 'InternalServerError');

        $response = $this->handler()->handle(self::request(self::credentials()));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('login throttle clean-up failed after a successful login', $this->logged());
        self::assertStringContainsString('"requestId":"req-1"', $this->logged());
    }

    // --- blocked -------------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function blockedBuckets(): iterable
    {
        yield 'account' => ['LOGIN-EMAIL', hash('sha256', self::EMAIL), ThrottleRule::LOGIN_ACCOUNT_LIMIT];
        yield 'address' => ['LOGIN-IP', self::ADDRESS_KEY, ThrottleRule::LOGIN_ADDRESS_LIMIT];
        yield 'network' => ['LOGIN-NET', self::NETWORK_KEY, ThrottleRule::LOGIN_NETWORK_LIMIT];
    }

    #[DataProvider('blockedBuckets')]
    public function testABlockedAttemptIs429WithRetryAfterAndNeverReadsOrVerifiesEvenWithTheRightPassword(string $scope, string $key, int $limit): void
    {
        $this->givenAnAccount();
        $this->throttleStore->seed($scope, $key, $limit);
        $this->throttleStore->retryAfterByScope[$scope] = 321;

        $problem = $this->problemOf(self::request(self::credentials()), self::spy());

        self::assertSame(429, $problem->problem->status);
        self::assertSame(['321'], $problem->headers['Retry-After'] ?? null);
        self::assertSame([], $this->commands, 'no lookup, so no verification of any password');
        self::assertCount(2, $this->dynamo, 'the scripted account was never read');
        self::assertSame([], $this->sessions->sessions);
        self::assertSame([], $this->cleanUpCalls(), 'a blocked attempt never reaches succeeded()');
    }

    public function testABlockedAttemptForAnUnknownEmailDoesNotEvenDoTheDummyRead(): void
    {
        $this->throttleStore->seed('LOGIN-IP', self::ADDRESS_KEY, ThrottleRule::LOGIN_ADDRESS_LIMIT);

        $problem = $this->problemOf(self::request(self::credentials('nobody@example.com')));

        self::assertSame(429, $problem->problem->status);
        self::assertSame([], $this->commands);
    }

    public function testTheBlockedBodyNeverSaysWhichBucketFiredAndABlockedAttemptIsLogged(): void
    {
        $this->throttleStore->seed('LOGIN-EMAIL', $this->accountKey(), ThrottleRule::LOGIN_ACCOUNT_LIMIT);

        $problem = $this->problemOf(self::request(self::credentials()));

        self::assertSame('Too many attempts. Try again later.', $problem->problem->detail);
        self::assertStringContainsString('login failed', $this->logged());
        self::assertStringContainsString('throttled', $this->logged());
        self::assertStringNotContainsString(self::EMAIL, $this->logged());
    }

    public function testEveryAttemptCountsInTheThreeBucketsBeforeAnythingIsRead(): void
    {
        $this->givenNoAccount();

        $this->problemOf(self::request(self::credentials()));

        self::assertSame([
            'hit|LOGIN-EMAIL|' . $this->accountKey(),
            'hit|LOGIN-IP|' . self::ADDRESS_KEY,
            'hit|LOGIN-NET|' . self::NETWORK_KEY,
        ], $this->throttleStore->calls);
    }

    public function testAnUnknownAddressSharesTheUnknownBucketAndAMissingAttributeToo(): void
    {
        $this->givenNoAccount();
        $this->givenNoAccount();

        $this->problemOf(self::request(self::credentials(), address: ClientAddress::unknown('missing')));
        $bare = new Request('POST', '/api/v1/auth/login', [], ['content-type' => 'application/json'], self::credentials());
        try {
            $this->handler()->handle($bare);
        } catch (HttpProblem) {
        }

        self::assertSame(2, $this->throttleStore->count('LOGIN-IP', ClientAddress::UNKNOWN_KEY));
    }

    // --- input ---------------------------------------------------------------------------------------------------

    public function testAnInvalidEmailAndAnOverLongPasswordAreReportedTogetherAndNothingIsCountedOrRead(): void
    {
        $problem = $this->problemOf(self::request(self::credentials('not-an-email', str_repeat('p', 129))), self::spy());

        self::assertSame(422, $problem->problem->status);
        self::assertSame([
            ['pointer' => '/email', 'detail' => 'Enter a valid email address.'],
            ['pointer' => '/password', 'detail' => 'The password must have at most 128 characters.'],
        ], self::fieldErrors($problem));
        self::assertSame([], $this->commands);
        self::assertSame([], $this->throttleStore->calls);
        self::assertStringNotContainsString('not-an-email', $problem->getMessage() . json_encode($problem->problem->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testAnOverLongPasswordIsRefusedTheSameWayWhetherOrNotTheEmailHasAnAccount(): void
    {
        $long = str_repeat('p', 129);
        $existing = $this->problemOf(self::request(self::credentials(self::EMAIL, $long)));
        $unknown = $this->problemOf(self::request(self::credentials('nobody@example.com', $long)));

        self::assertSame(422, $existing->problem->status);
        self::assertEquals($existing->problem, $unknown->problem);
        self::assertSame([], $this->commands, 'neither request touched the database');
    }

    public function testAMissingOrNonStringMemberIs422AtItsPointer(): void
    {
        $problem = $this->problemOf(self::request('{"email":"ana@example.com"}'));

        self::assertSame(422, $problem->problem->status);
        self::assertSame([['pointer' => '/password', 'detail' => 'This field is required.']], self::fieldErrors($problem));
        self::assertSame([], $this->throttleStore->calls);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, int}>
     */
    public static function badBodies(): iterable
    {
        yield 'wrong content type' => [self::credentials(), ['content-type' => 'text/plain'], 415];
        yield 'too large' => ['{"email":"' . str_repeat('a', 65536) . '"}', [], 413];
        yield 'not json' => ['{"email":', [], 400];
        yield 'not an object' => ['["a","b"]', [], 400];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('badBodies')]
    public function testABodyThatCannotBeReadKeepsItsStatusAndTouchesNothing(string $body, array $headers, int $status): void
    {
        $problem = $this->problemOf(self::request($body, $headers), self::spy());

        self::assertSame($status, $problem->problem->status);
        self::assertSame([], $this->commands);
        self::assertSame([], $this->throttleStore->calls);
    }

    // --- storage failures ----------------------------------------------------------------------------------------

    public function testAStorageFailureOnTheLookupPropagatesAndNothingIsRefunded(): void
    {
        $this->dynamo->append(new DynamoDbException('boom', new \Aws\Command('GetItem'), ['code' => 'ValidationException']));

        try {
            $this->handler()->handle(self::request(self::credentials()));
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('ValidationException', $failure->awsErrorCode);
        }

        self::assertSame([], $this->cleanUpCalls());
        self::assertSame([], $this->sessions->sessions);
    }

    public function testAStorageFailureOnTheDummyReadPropagates(): void
    {
        $this->dynamo->append(new Result([]));
        $this->dynamo->append(new DynamoDbException('boom', new \Aws\Command('GetItem'), ['code' => 'InternalServerError']));

        $this->expectException(StorageFailure::class);

        $this->handler()->handle(self::request(self::credentials()));
    }

    public function testACorruptUserItemIsAStorageFailureNotALoginOrA401(): void
    {
        $this->dynamo->append(new Result(['Item' => ['PK' => ['S' => 'EMAIL#ana@example.com'], 'SK' => ['S' => 'EMAIL'], 'userId' => ['S' => self::USER_ID]]]));
        $this->dynamo->append(new Result(['Item' => ['PK' => ['S' => 'USER#' . self::USER_ID], 'SK' => ['S' => 'PROFILE'], 'userId' => ['S' => self::USER_ID]]]));

        try {
            $this->handler()->handle(self::request(self::credentials()));
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('CorruptItem', $failure->awsErrorCode);
        }

        self::assertSame([], $this->cleanUpCalls());
    }

    public function testAFailureToCreateTheSessionPropagatesSoNoCookieIsSent(): void
    {
        $this->givenAnAccount();
        $this->sessions->createFailure = new StorageFailure('PutItem', 'InternalServerError');

        $this->expectException(StorageFailure::class);

        $this->handler()->handle(self::request(self::credentials()));
    }
}
