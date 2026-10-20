<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Http;

use App\Auth\Http\MeHandler;
use App\Auth\Session\Principal;
use App\Auth\Session\SessionToken;
use App\Auth\User\UserRepository;
use App\Http\Request;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\InMemorySessionStore;
use App\Tests\Support\TestPasswordHashers;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class MeHandlerTest extends TestCase
{
    private const USER_ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const EMAIL = 'ana@example.com';
    private const CLEARING = '__Host-session=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax';

    private MockHandler $dynamo;

    /** @var list<CommandInterface> */
    private array $commands = [];

    private InMemorySessionStore $sessions;

    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $this->dynamo = new MockHandler();
        $this->commands = [];
        $this->sessions = new InMemorySessionStore(new FrozenClock());
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->log = $stream;
    }

    private function handler(): MeHandler
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;
            $promise = ($this->dynamo)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $client = DynamoDbClientFactory::create($settings, $recording);

        return new MeHandler(new UserRepository(new ItemStore($client, $settings->tableName)), $this->sessions, new JsonLogger($this->log));
    }

    private static function request(?Principal $principal): Request
    {
        $request = new Request('GET', '/api/v1/auth/me', [], [], '', [Request::REQUEST_ID => 'req-1']);

        return $principal === null ? $request : $request->withAttribute(Principal::ATTRIBUTE, $principal);
    }

    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    private function givenTheUser(): void
    {
        $this->dynamo->append(new Result(['Item' => [
            'PK' => ['S' => 'USER#' . self::USER_ID],
            'SK' => ['S' => 'PROFILE'],
            'userId' => ['S' => self::USER_ID],
            'email' => ['S' => self::EMAIL],
            'passwordHash' => ['S' => TestPasswordHashers::cheap()->hash('correct horse staple 42')],
            'createdAt' => ['S' => '2026-10-16T12:00:00Z'],
        ]]));
    }

    public function testItAnswers200WithOnlyTheIdAndTheEmail(): void
    {
        $token = $this->sessions->seed(self::USER_ID);
        $this->givenTheUser();

        $response = $this->handler()->handle(self::request(new Principal(self::USER_ID, $token->hash())));

        self::assertSame(200, $response->status);
        self::assertSame(['application/json'], $response->headerValues('Content-Type'));
        self::assertSame('{"id":"' . self::USER_ID . '","email":"' . self::EMAIL . '"}', $response->body);
        self::assertSame([], $response->headerValues('Set-Cookie'));
        self::assertStringNotContainsString('argon', $response->body);
    }

    public function testItReadsExactlyTheUserOfThePrincipal(): void
    {
        $token = $this->sessions->seed(self::USER_ID);
        $this->givenTheUser();

        $this->handler()->handle(self::request(new Principal(self::USER_ID, $token->hash())));

        self::assertCount(1, $this->commands);
        self::assertSame('GetItem', $this->commands[0]->getName());
        $key = $this->commands[0]->toArray()['Key'] ?? null;
        self::assertIsArray($key);
        self::assertSame(['PK' => ['S' => 'USER#' . self::USER_ID], 'SK' => ['S' => 'PROFILE']], $key);
        self::assertSame([], $this->sessions->operations, 'a read of an existing user does not touch the session');
    }

    public function testAMissingUserGives401WithTheChallengeTheClearingCookieAndEndsTheSession(): void
    {
        $token = $this->sessions->seed(self::USER_ID);
        $this->dynamo->append(new Result([]));

        $response = $this->handler()->handle(self::request(new Principal(self::USER_ID, $token->hash())));

        self::assertSame(401, $response->status);
        self::assertSame(['Session'], $response->headerValues('WWW-Authenticate'));
        self::assertSame([self::CLEARING], $response->headerValues('Set-Cookie'));
        self::assertSame('application/problem+json', $response->headerValues('Content-Type')[0]);
        self::assertSame([], $this->sessions->sessions);
        self::assertSame(['delete'], $this->sessions->operations);
    }

    public function testTheLogLineForAMissingUserHasNoTokenAndNoHash(): void
    {
        $token = SessionToken::generate();
        $this->sessions->put($token->hash(), \App\Auth\Session\Session::start(self::USER_ID, $token->hash(), 1_000));
        $this->dynamo->append(new Result([]));

        $this->handler()->handle(self::request(new Principal(self::USER_ID, $token->hash())));

        self::assertStringContainsString('session of a missing user ended', $this->logged());
        self::assertStringNotContainsString($token->cookieValue(), $this->logged());
        self::assertStringNotContainsString($token->hash(), $this->logged());
    }

    public function testWithoutAPrincipalItFailsLoudlyAndReadsNothing(): void
    {
        try {
            $this->handler()->handle(self::request(null));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->commands);
        }
    }

    public function testAStorageFailureOnTheReadPropagates(): void
    {
        $token = $this->sessions->seed(self::USER_ID);
        $this->dynamo->append(new Result(['Item' => ['PK' => ['S' => 'USER#' . self::USER_ID], 'SK' => ['S' => 'PROFILE'], 'userId' => ['S' => 'someone-else']]]));

        $this->expectException(StorageFailure::class);

        $this->handler()->handle(self::request(new Principal(self::USER_ID, $token->hash())));
    }

    public function testAFailureToEndTheSessionOfAMissingUserPropagates(): void
    {
        $token = $this->sessions->seed(self::USER_ID);
        $this->sessions->deleteFailure = new StorageFailure('DeleteItem', 'InternalServerError');
        $this->dynamo->append(new Result([]));

        $this->expectException(StorageFailure::class);

        $this->handler()->handle(self::request(new Principal(self::USER_ID, $token->hash())));
    }
}
