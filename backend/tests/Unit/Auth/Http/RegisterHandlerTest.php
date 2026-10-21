<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Http;

use App\Auth\Http\RegisterHandler;
use App\Auth\Password\PasswordHasher;
use App\Auth\Password\PasswordPolicy;
use App\Auth\Session\CarriedSession;
use App\Auth\Session\SessionLifetime;
use App\Auth\Throttle\RegisterThrottle;
use App\Auth\Throttle\ThrottleRule;
use App\Auth\User\UserRepository;
use App\Http\ClientAddress;
use App\Http\Handler;
use App\Http\Middleware\ClientIpMiddleware;
use App\Http\Middleware\StorageUnavailableMiddleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Id\IdGenerator;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\InMemorySessionStore;
use App\Tests\Support\InMemoryThrottleStore;
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
 * The register flow with fakes: an in-memory throttle and session store, and the real user repository over a scripted
 * DynamoDB. The real behaviour of the pieces is covered by their own tests; this is about the order and the mapping.
 */
final class RegisterHandlerTest extends TestCase
{
    private const USER_ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const PASSWORD = 'correct horse staple 42';
    private const EMAIL = 'ana@example.com';
    private const ADDRESS_KEY = '2001:db8:1:2';

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

    private function handler(?PasswordHasher $hasher = null): RegisterHandler
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;
            $promise = ($this->dynamo)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $client = DynamoDbClientFactory::create($settings, $recording);

        return new RegisterHandler(
            new RegisterThrottle($this->throttleStore),
            new PasswordPolicy(),
            $hasher ?? TestPasswordHashers::cheap(),
            new UserRepository(new ItemStore($client, $settings->tableName)),
            $this->sessions,
            new CarriedSession($this->sessions, new JsonLogger($this->logStream)),
            new class (self::USER_ID) implements IdGenerator {
                public function __construct(private readonly string $id) {}

                public function generate(): string
                {
                    return $this->id;
                }
            },
            $this->clock,
            new JsonLogger($this->logStream),
        );
    }

    /** A hasher that throws if it is used at all (a memory cost of 0 is refused by PHP). */
    private static function hasherThatMustNotRun(): PasswordHasher
    {
        return new PasswordHasher(0, 0, 0);
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

        return new Request('POST', '/api/v1/auth/register', [], $headers + ['content-type' => 'application/json'], $body, $attributes);
    }

    private static function credentials(string $email = self::EMAIL, string $password = self::PASSWORD): string
    {
        return json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR);
    }

    private static function cancelled(string ...$codes): DynamoDbException
    {
        $reasons = array_map(static fn(string $code): array => ['Code' => $code], $codes);

        return new DynamoDbException('cancelled', new \Aws\Command('TransactWriteItems'), ['code' => 'TransactionCanceledException', 'body' => ['CancellationReasons' => $reasons]]);
    }

    private function logged(): string
    {
        rewind($this->logStream);

        return (string) stream_get_contents($this->logStream);
    }

    private function problemOf(Request $request, ?PasswordHasher $hasher = null): HttpProblem
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

    /**
     * The attribute map of one Put of the first TransactWriteItems sent (0: the user, 1: the e-mail lock).
     *
     * @return array<array-key, mixed>
     */
    private function putItem(int $index): array
    {
        $items = $this->commands[0]['TransactItems'] ?? null;
        self::assertIsArray($items);
        $operation = $items[$index] ?? null;
        self::assertIsArray($operation);
        $put = $operation['Put'] ?? null;
        self::assertIsArray($put);
        $item = $put['Item'] ?? null;
        self::assertIsArray($item);

        return $item;
    }

    private function storedString(int $index, string $attribute): string
    {
        $value = $this->putItem($index)[$attribute] ?? null;
        self::assertIsArray($value);
        self::assertIsString($value['S'] ?? null);

        return $value['S'];
    }

    public function testItCreatesTheUserAndSignsThemInWith201(): void
    {
        $this->dynamo->append(new Result([]));

        $response = $this->handler()->handle(self::request(self::credentials(' Ana@Example.COM ')));

        self::assertSame(201, $response->status);
        self::assertSame(['application/json'], $response->headerValues('Content-Type'));
        self::assertSame(['id' => self::USER_ID, 'email' => self::EMAIL], json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
        self::assertCount(1, $this->commands);
        self::assertSame('EMAIL#ana@example.com', $this->storedString(1, 'PK'));
    }

    public function testTheCookieCarriesAFreshTokenWithTheExactAttributes(): void
    {
        $this->dynamo->append(new Result([]));

        $response = $this->handler()->handle(self::request(self::credentials()));

        $cookies = $response->headerValues('Set-Cookie');
        self::assertCount(1, $cookies);
        self::assertMatchesRegularExpression(
            '/\A__Host-session=[A-Za-z0-9_-]{43}; Max-Age=' . SessionLifetime::ABSOLUTE . '; Path=\/; Secure; HttpOnly; SameSite=Lax\z/',
            $cookies[0],
        );
        self::assertCount(1, $this->sessions->sessions);
        $session = array_values($this->sessions->sessions)[0];
        self::assertSame(self::USER_ID, $session->userId);
        self::assertStringNotContainsString($session->tokenHash, $response->body);
    }

    public function testTheStoredHashIsAnArgon2idHashOfThePasswordNotThePassword(): void
    {
        $this->dynamo->append(new Result([]));

        $this->handler()->handle(self::request(self::credentials()));

        $hash = $this->storedString(0, 'passwordHash');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(password_verify(self::PASSWORD, $hash));
    }

    public function testASuccessIsLoggedWithTheUserIdAndNeverTheEmailOrPassword(): void
    {
        $this->dynamo->append(new Result([]));
        $context = new RequestLogContext();

        $this->handler()->handle(self::request(self::credentials(), context: $context));

        self::assertStringContainsString('user registered', $this->logged());
        self::assertStringContainsString(self::USER_ID, $this->logged());
        self::assertStringNotContainsString('ana@example.com', $this->logged());
        self::assertStringNotContainsString(self::PASSWORD, $this->logged());
        self::assertStringNotContainsString('__Host-session', $this->logged());
        self::assertSame(self::USER_ID, $context->userId());
    }

    public function testAThrottledRequestIs429WithRetryAfterAndNothingIsHashedOrStored(): void
    {
        $this->throttleStore->seed(ThrottleRule::registerAddress()->scope, self::ADDRESS_KEY, ThrottleRule::REGISTER_ADDRESS_LIMIT);
        $this->throttleStore->retryAfterByScope[ThrottleRule::registerAddress()->scope] = 1234;

        $problem = $this->problemOf(self::request(self::credentials()), self::hasherThatMustNotRun());

        self::assertSame(429, $problem->problem->status);
        self::assertSame(['1234'], $problem->headers['Retry-After']);
        self::assertSame([], $this->commands);
        self::assertSame([], $this->sessions->sessions);
    }

    public function testTheThrottleAnswersBeforeTheBodyIsEvenRead(): void
    {
        $this->throttleStore->seed(ThrottleRule::registerAddress()->scope, self::ADDRESS_KEY, ThrottleRule::REGISTER_ADDRESS_LIMIT);

        $problem = $this->problemOf(self::request('not json at all', ['content-type' => 'text/plain']), self::hasherThatMustNotRun());

        self::assertSame(429, $problem->problem->status);
    }

    public function testABlockedRegistrationIsLoggedAsAWarningWithTheReasonAndNeitherTheEmailNorTheAddress(): void
    {
        $this->throttleStore->seed(ThrottleRule::registerAddress()->scope, self::ADDRESS_KEY, ThrottleRule::REGISTER_ADDRESS_LIMIT);

        $problem = $this->problemOf(self::request(self::credentials()));

        self::assertSame(429, $problem->problem->status);
        $line = json_decode(trim($this->logged()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($line);
        self::assertSame('warning', $line['level']);
        self::assertSame('registration failed', $line['message']);
        self::assertSame('throttled', $line['reason']);
        self::assertSame('req-1', $line['requestId']);
        foreach ([self::EMAIL, self::ADDRESS_KEY, '2001:db8'] as $secret) {
            self::assertStringNotContainsString($secret, $this->logged());
        }
    }

    public function testEveryAttemptCountsEvenOneThatFailsValidation(): void
    {
        $this->problemOf(self::request(self::credentials(password: 'short')));
        $this->problemOf(self::request('{', ['content-type' => 'application/json']));

        self::assertSame(2, $this->throttleStore->count(ThrottleRule::registerAddress()->scope, self::ADDRESS_KEY));
    }

    public function testAnUnknownAddressSharesTheUnknownBucket(): void
    {
        $this->dynamo->append(new Result([]));

        $this->handler()->handle(self::request(self::credentials(), address: ClientAddress::unknown('missing')));

        self::assertSame(1, $this->throttleStore->count(ThrottleRule::registerAddress()->scope, ClientAddress::UNKNOWN_KEY));
    }

    public function testARequestWithoutAClientAddressAttributeIsCountedAsUnknownNotExempt(): void
    {
        $this->dynamo->append(new Result([]));
        $request = new Request('POST', '/api/v1/auth/register', [], ['content-type' => 'application/json'], self::credentials());

        $this->handler()->handle($request);

        self::assertSame(1, $this->throttleStore->count(ThrottleRule::registerAddress()->scope, ClientAddress::UNKNOWN_KEY));
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
    public function testABodyThatCannotBeReadKeepsItsStatusAndHashesNothing(string $body, array $headers, int $status): void
    {
        $problem = $this->problemOf(self::request($body, $headers), self::hasherThatMustNotRun());

        self::assertSame($status, $problem->problem->status);
        self::assertSame([], $this->commands);
    }

    public function testAWrongShapeIs422WithThePointersAndNothingIsHashed(): void
    {
        $problem = $this->problemOf(self::request('{"email":"ana@example.com","role":"admin"}'), self::hasherThatMustNotRun());

        self::assertSame(422, $problem->problem->status);
        self::assertSame(['/password', '/role'], array_column(self::fieldErrors($problem), 'pointer'));
    }

    public function testAShortPasswordIs422AtPasswordWithoutEchoingIt(): void
    {
        $problem = $this->problemOf(self::request(self::credentials(password: 'short-pw')), self::hasherThatMustNotRun());

        self::assertSame(422, $problem->problem->status);
        $errors = self::fieldErrors($problem);
        self::assertSame(['/password'], array_column($errors, 'pointer'));
        self::assertStringNotContainsString('short-pw', json_encode($errors, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->commands);
    }

    public function testAnInvalidEmailAndAnInvalidPasswordAreReportedTogether(): void
    {
        $problem = $this->problemOf(self::request(self::credentials('not-an-email', 'short')), self::hasherThatMustNotRun());

        $errors = self::fieldErrors($problem);
        self::assertSame(['/email', '/password'], array_column($errors, 'pointer'));
        self::assertStringNotContainsString('not-an-email', json_encode($errors, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function badValues(): iterable
    {
        yield 'control character in the email' => ["ana\x07@example.com", self::PASSWORD, '/email'];
        yield 'NUL at the edge of the email' => ["ana@example.com\x00", self::PASSWORD, '/email'];
        yield 'empty email' => ['', self::PASSWORD, '/email'];
        yield 'non-ASCII email' => ["an\u{e1}@example.com", self::PASSWORD, '/email'];
        yield 'email too long' => [str_repeat('a', 250) . '@example.com', self::PASSWORD, '/email'];
        yield 'control character in the password' => [self::EMAIL, "correct horse\x07staple 42", '/password'];
        yield 'C1 control character in the password' => [self::EMAIL, "correct horse\u{85}staple 42", '/password'];
        yield 'password too long' => [self::EMAIL, str_repeat('p', 129), '/password'];
        yield 'empty password' => [self::EMAIL, '', '/password'];
    }

    #[DataProvider('badValues')]
    public function testValuesThatBreakTheRulesAre422AtTheirPointer(string $email, string $password, string $pointer): void
    {
        $problem = $this->problemOf(self::request(self::credentials($email, $password)), self::hasherThatMustNotRun());

        self::assertSame(422, $problem->problem->status);
        self::assertSame([$pointer], array_column(self::fieldErrors($problem), 'pointer'));
    }

    public function testAnEmailTakenByAnotherUserIs409WithoutASessionOrTheAddress(): void
    {
        $this->dynamo->append(self::cancelled('None', 'ConditionalCheckFailed'));
        $this->dynamo->append(new Result(['Item' => [
            'PK' => ['S' => 'EMAIL#ana@example.com'],
            'SK' => ['S' => 'EMAIL'],
            'userId' => ['S' => '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d'],
        ]]));

        $problem = $this->problemOf(self::request(self::credentials()));

        self::assertSame(409, $problem->problem->status);
        self::assertSame([], $this->sessions->sessions);
        self::assertStringNotContainsString('ana@example.com', json_encode($problem->problem->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('user registered', $this->logged());
    }

    public function testTheConflictWordingAdmitsThatAnEarlierAttemptMayHaveWorked(): void
    {
        $this->dynamo->append(self::cancelled('None', 'ConditionalCheckFailed'));
        $this->dynamo->append(new Result(['Item' => [
            'PK' => ['S' => 'EMAIL#ana@example.com'],
            'SK' => ['S' => 'EMAIL'],
            'userId' => ['S' => '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d'],
        ]]));

        $problem = $this->problemOf(self::request(self::credentials()));

        self::assertSame(
            'An account with this email address already exists. If your previous attempt ended with a temporary error, it may have gone through: try signing in.',
            $problem->problem->detail,
        );
    }

    public function testARetriedWriteThatFindsItsOwnUserIsReportedAs201(): void
    {
        $this->dynamo->append(self::cancelled('ConditionalCheckFailed', 'ConditionalCheckFailed'));
        $this->dynamo->append(fn() => new Result(['Item' => $this->putItem(1)]));
        $this->dynamo->append(fn() => new Result(['Item' => $this->putItem(0)]));

        $response = $this->handler()->handle(self::request(self::credentials()));

        self::assertSame(201, $response->status);
        self::assertCount(1, $response->headerValues('Set-Cookie'));
        self::assertCount(1, $this->sessions->sessions);
    }

    public function testASessionTheRequestCarriedIsDeletedAndANewOneIssued(): void
    {
        $this->dynamo->append(new Result([]));
        $old = $this->sessions->seed('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');

        $response = $this->handler()->handle(self::request(self::credentials(), ['cookie' => '__Host-session=' . $old->cookieValue()]));

        self::assertSame(201, $response->status);
        self::assertArrayNotHasKey($old->hash(), $this->sessions->sessions);
        self::assertCount(1, $this->sessions->sessions);
        self::assertStringNotContainsString($old->cookieValue(), $response->headerValues('Set-Cookie')[0]);
    }

    public function testAMalformedOrDuplicateSessionCookieDeletesNothing(): void
    {
        $this->dynamo->append(new Result([]), new Result([]));
        $other = $this->sessions->seed('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');
        $callsBefore = $this->sessions->calls;

        $this->handler()->handle(self::request(self::credentials(), ['cookie' => '__Host-session=not-a-token']));
        $this->handler()->handle(self::request(self::credentials('bo@example.com'), [
            'cookie' => '__Host-session=' . $other->cookieValue() . '; __Host-session=' . $other->cookieValue(),
        ]));

        self::assertArrayHasKey($other->hash(), $this->sessions->sessions);
        // Two creates and no delete.
        self::assertSame($callsBefore + 2, $this->sessions->calls);
    }

    public function testTheCarriedSessionIsNotDeletedWhenTheRegistrationFails(): void
    {
        $old = $this->sessions->seed('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');

        $this->problemOf(self::request(self::credentials(password: 'short'), ['cookie' => '__Host-session=' . $old->cookieValue()]));

        self::assertArrayHasKey($old->hash(), $this->sessions->sessions);
    }

    public function testAnOverloadedTransactionReachesTheStorageMiddlewareAs503WithRetryAfter(): void
    {
        $this->dynamo->append(self::cancelled('ThrottlingError', 'None'));
        $handler = $this->handler();
        $middleware = new StorageUnavailableMiddleware(new JsonLogger($this->logStream));

        try {
            $middleware->process(self::request(self::credentials()), new class ($handler) implements Handler {
                public function __construct(private readonly RegisterHandler $inner) {}

                public function handle(Request $request): Response
                {
                    return $this->inner->handle($request);
                }
            });
            self::fail('expected the 503 problem');
        } catch (HttpProblem $problem) {
            self::assertSame(503, $problem->problem->status);
            self::assertSame(['1'], $problem->headers['Retry-After']);
        }
        self::assertSame([], $this->sessions->sessions);
    }

    public function testAFailureCreatingTheSessionPropagatesAndTheRegistrationIsNotLogged(): void
    {
        $this->dynamo->append(new Result([]));
        $this->sessions->createFailure = new StorageFailure('PutItem', 'ThrottlingException');

        $this->expectException(StorageFailure::class);
        try {
            $this->handler()->handle(self::request(self::credentials()));
        } finally {
            self::assertStringNotContainsString('user registered', $this->logged());
        }
    }

    public function testAFailureEndingTheCarriedSessionStillGives201WithTheNewCookieAndALoggedWarning(): void
    {
        $this->dynamo->append(new Result([]));
        $old = $this->sessions->seed('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');
        $this->sessions->deleteFailure = new StorageFailure('DeleteItem', 'ThrottlingException');

        $response = $this->handler()->handle(self::request(self::credentials(), ['cookie' => '__Host-session=' . $old->cookieValue()], context: new RequestLogContext()));

        self::assertSame(201, $response->status);
        self::assertCount(1, $response->headerValues('Set-Cookie'));
        self::assertStringContainsString('"level":"warning"', $this->logged());
        self::assertStringContainsString('ThrottlingException', $this->logged());
        self::assertStringNotContainsString($old->cookieValue(), $this->logged());
        self::assertStringNotContainsString($old->hash(), $this->logged());
        self::assertStringContainsString('user registered', $this->logged());
    }

    public function testTheNewSessionIsCreatedBeforeTheCarriedOneIsDeleted(): void
    {
        $this->dynamo->append(new Result([]));
        $old = $this->sessions->seed('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');

        $this->handler()->handle(self::request(self::credentials(), ['cookie' => '__Host-session=' . $old->cookieValue()]));

        self::assertSame(['create', 'delete'], $this->sessions->operations);
    }
}
