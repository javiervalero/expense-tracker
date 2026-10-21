<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Session;

use App\Auth\Session\Session;
use App\Auth\Session\SessionLifetime;
use App\Auth\Session\SessionRepository;
use App\Auth\Session\SessionStore;
use App\Auth\Session\SessionToken;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Tests\Support\FrozenClock;
use Aws\CommandInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The branches of SessionRepository that need a scripted DynamoDB answer; the real behaviour is in the integration test.
 * Ids and times are invented.
 */
final class SessionRepositoryTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_ID = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const NOW = 1_800_000_000;

    private MockHandler $mock;
    private FrozenClock $clock;

    /** @var list<CommandInterface> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->commands = [];
        $this->mock = new MockHandler();
        $this->clock = new FrozenClock('@' . self::NOW);
    }

    /**
     * @param list<SessionToken> $tokens handed out in order
     */
    private function repository(array $tokens = []): SessionRepository
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;

            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $items = new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName);
        $generate = static function () use (&$tokens): SessionToken {
            $next = array_shift($tokens);
            if ($next === null) {
                throw new \LogicException('The test ran out of tokens.');
            }

            return $next;
        };

        return new SessionRepository($items, $this->clock, $generate);
    }

    private static function conditionFailed(): DynamoDbException
    {
        return new DynamoDbException('failed', new \Aws\Command('PutItem'), ['code' => 'ConditionalCheckFailedException']);
    }

    private static function failure(string $code): DynamoDbException
    {
        return new DynamoDbException('failed', new \Aws\Command('PutItem'), ['code' => $code]);
    }

    /**
     * @param array<string, mixed> $override a null value removes the attribute; numbers are given as int
     *
     * @return array<string, array<string, string>>
     */
    private static function rawItem(string $hash, array $override = []): array
    {
        $fields = $override + [
            'PK' => 'SESSION#' . $hash,
            'SK' => 'SESSION',
            'userId' => self::ID,
            'createdAt' => self::NOW,
            'renewedAt' => self::NOW,
            'expiresAt' => self::NOW + SessionLifetime::IDLE,
            'absoluteExpiresAt' => self::NOW + SessionLifetime::ABSOLUTE,
        ];
        $raw = [];
        foreach ($fields as $name => $value) {
            if ($value === null) {
                continue;
            }
            $raw[$name] = is_int($value) ? ['N' => (string) $value] : ['S' => is_string($value) ? $value : throw new \LogicException('Use a string or an int.')];
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return Result<string, mixed>
     */
    private static function stored(string $hash, array $override = []): Result
    {
        return new Result(['Item' => self::rawItem($hash, $override)]);
    }

    private function assertCommandNames(string ...$names): void
    {
        self::assertSame($names, array_map(static fn(CommandInterface $command): string => $command->getName(), $this->commands));
    }

    public function testCreateRefusesAnInvalidUserIdAndSendsNothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->repository([SessionToken::generate()])->create('not-a-uuid');
        } finally {
            self::assertSame([], $this->commands);
        }
    }

    public function testACreateThatFindsItsOwnItemAfterAFailedConditionReturnsTheSameToken(): void
    {
        $token = SessionToken::generate();
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::stored($token->hash()));

        $created = $this->repository([$token])->create(self::ID);

        self::assertSame($token->hash(), $created->hash());
        $this->assertCommandNames('PutItem', 'GetItem');
    }

    public function testACreateWhoseConditionFailedAndWhoseItemIsGoneTriesTheNextToken(): void
    {
        $first = SessionToken::generate();
        $second = SessionToken::generate();
        $this->mock->append(self::conditionFailed());
        $this->mock->append(new Result([]));
        $this->mock->append(new Result([]));

        $created = $this->repository([$first, $second])->create(self::ID);

        self::assertSame($second->hash(), $created->hash());
        $this->assertCommandNames('PutItem', 'GetItem', 'PutItem');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function itemsThatAreNotOurOwnEarlierAttempt(): iterable
    {
        yield 'another user' => [['userId' => self::OTHER_ID]];
        yield 'another creation time' => [['createdAt' => self::NOW - 5, 'renewedAt' => self::NOW - 5, 'absoluteExpiresAt' => self::NOW - 5 + SessionLifetime::ABSOLUTE]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('itemsThatAreNotOurOwnEarlierAttempt')]
    public function testACreateThatFindsSomeoneElsesItemTriesTheNextToken(array $override): void
    {
        $first = SessionToken::generate();
        $second = SessionToken::generate();
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::stored($first->hash(), $override));
        $this->mock->append(new Result([]));

        $created = $this->repository([$first, $second])->create(self::ID);

        self::assertSame($second->hash(), $created->hash());
        $this->assertCommandNames('PutItem', 'GetItem', 'PutItem');
    }

    public function testTwoCollisionsFailWithFourCommandsAndNoTokenInTheMessage(): void
    {
        $first = SessionToken::generate();
        $second = SessionToken::generate();
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::stored($first->hash(), ['userId' => self::OTHER_ID]));
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::stored($second->hash(), ['userId' => self::OTHER_ID]));

        try {
            $this->repository([$first, $second])->create(self::ID);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('SessionCollision', $failure->awsErrorCode);
            foreach ([$first, $second] as $token) {
                self::assertStringNotContainsString($token->cookieValue(), $failure->getMessage());
                self::assertStringNotContainsString($token->hash(), $failure->getMessage());
            }
        }
        $this->assertCommandNames('PutItem', 'GetItem', 'PutItem', 'GetItem');
    }

    public function testAPutErrorThatIsNotAFailedConditionPropagatesWithoutRetry(): void
    {
        $this->mock->append(self::failure('AccessDeniedException'));

        try {
            $this->repository([SessionToken::generate(), SessionToken::generate()])->create(self::ID);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('AccessDeniedException', $failure->awsErrorCode);
        }
        $this->assertCommandNames('PutItem');
    }

    public function testACreateWhoseReReadFindsACorruptItemIsACorruptItemFailure(): void
    {
        $token = SessionToken::generate();
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::stored($token->hash(), ['userId' => 'USER#x']));

        try {
            $this->repository([$token])->create(self::ID);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
        }
        $this->assertCommandNames('PutItem', 'GetItem');
    }

    public function testFindOfAnAbsentItemIsNull(): void
    {
        $this->mock->append(new Result([]));

        self::assertNull($this->repository()->find(hash('sha256', 'invented'), self::NOW));
    }

    public function testFindBuildsASessionFromAValidItem(): void
    {
        $hash = hash('sha256', 'invented');
        $this->mock->append(self::stored($hash));

        $session = $this->repository()->find($hash, self::NOW);

        self::assertEquals(Session::start(self::ID, $hash, self::NOW), $session);
    }

    public function testFindIgnoresItemsPastTheirIdleOrAbsoluteExpiry(): void
    {
        $hash = hash('sha256', 'invented');
        $this->mock->append(self::stored($hash, ['expiresAt' => self::NOW]));
        $this->mock->append(self::stored($hash, ['createdAt' => self::NOW - 1000, 'renewedAt' => self::NOW - 1000, 'absoluteExpiresAt' => self::NOW, 'expiresAt' => self::NOW - 1]));
        $this->mock->append(self::stored($hash, ['createdAt' => self::NOW - 1000, 'renewedAt' => self::NOW - 1000, 'absoluteExpiresAt' => self::NOW, 'expiresAt' => self::NOW]));

        self::assertNull($this->repository()->find($hash, self::NOW));
        self::assertNull($this->repository()->find($hash, self::NOW));
        self::assertNull($this->repository()->find($hash, self::NOW));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function corruptItems(): iterable
    {
        yield 'wrong partition key' => [['PK' => 'SESSION#' . str_repeat('0', 64)]];
        yield 'partition key missing' => [['PK' => null]];
        yield 'wrong sort key' => [['SK' => 'PROFILE']];
        yield 'sort key missing' => [['SK' => null]];
        yield 'createdAt zero' => [['createdAt' => 0]];
        yield 'createdAt negative' => [['createdAt' => -1]];
        yield 'createdAt a string' => [['createdAt' => 'x']];
        yield 'userId not a uuid' => [['userId' => 'USER#x']];
        yield 'userId missing' => [['userId' => null]];
        yield 'userId a number' => [['userId' => 5]];
        yield 'renewed before created' => [['renewedAt' => self::NOW - 1]];
        yield 'idle past absolute' => [['expiresAt' => self::NOW + SessionLifetime::ABSOLUTE + 1]];
        yield 'created beyond the skew allowance' => [['createdAt' => self::NOW + 301, 'renewedAt' => self::NOW + 301, 'absoluteExpiresAt' => self::NOW + 301 + SessionLifetime::ABSOLUTE, 'expiresAt' => self::NOW + 301 + SessionLifetime::IDLE]];
        yield 'absolute equal to created' => [['absoluteExpiresAt' => self::NOW, 'expiresAt' => self::NOW]];
        yield 'absolute before created' => [['absoluteExpiresAt' => self::NOW - 1, 'expiresAt' => self::NOW - 1]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('corruptItems')]
    public function testFindOfACorruptItemIsACorruptItemFailure(array $override): void
    {
        $hash = hash('sha256', 'invented');
        $this->mock->append(self::stored($hash, $override));

        try {
            $this->repository()->find($hash, self::NOW);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
            self::assertSame('GetItem', $failure->operation);
        }
    }

    public function testAnItemCreatedWithinTheSkewAllowanceIsAccepted(): void
    {
        $hash = hash('sha256', 'invented');
        $created = self::NOW + 300;
        $this->mock->append(self::stored($hash, ['createdAt' => $created, 'renewedAt' => $created, 'absoluteExpiresAt' => $created + SessionLifetime::ABSOLUTE, 'expiresAt' => $created + SessionLifetime::IDLE]));

        self::assertNotNull($this->repository()->find($hash, self::NOW));
    }

    public function testAnAbsoluteExpiryBeyondTheCurrentCapIsCappedOnRead(): void
    {
        $hash = hash('sha256', 'invented');
        $cap = self::NOW + SessionLifetime::ABSOLUTE;
        $this->mock->append(self::stored($hash, ['absoluteExpiresAt' => $cap + 5000, 'expiresAt' => $cap + 4000]));
        $this->mock->append(self::stored($hash, ['absoluteExpiresAt' => $cap + 5000, 'expiresAt' => $cap + 4000]));

        $session = $this->repository()->find($hash, self::NOW);

        self::assertSame($cap, $session?->absoluteExpiresAt);
        self::assertSame($cap, $session->expiresAt);
        self::assertNull($this->repository()->find($hash, $cap));
    }

    public function testRenewOfACappedSessionNeverWritesPastTheCap(): void
    {
        $hash = hash('sha256', 'invented');
        $cap = self::NOW + SessionLifetime::ABSOLUTE;
        $this->mock->append(self::stored($hash, ['absoluteExpiresAt' => $cap + 5000, 'expiresAt' => $cap - 100]));
        $this->mock->append(new Result(['Attributes' => self::rawItem($hash, ['absoluteExpiresAt' => $cap + 5000, 'expiresAt' => $cap - 10, 'renewedAt' => $cap - 1000])]));
        $session = $this->repository()->find($hash, $cap - 1000);
        self::assertNotNull($session);

        $this->repository()->renew($session, $cap - 1000);

        /** @var array<string, array{N: string}> $values */
        $values = $this->commands[1]['ExpressionAttributeValues'];
        self::assertSame((string) $cap, $values[':expiresAt']['N']);
    }

    public function testAnAbsoluteExpiryExactlyAtTheCapIsAccepted(): void
    {
        $hash = hash('sha256', 'invented');
        $this->mock->append(self::stored($hash, ['absoluteExpiresAt' => self::NOW + SessionLifetime::ABSOLUTE]));

        self::assertNotNull($this->repository()->find($hash, self::NOW));
    }

    public function testRenewSendsAConditionalUpdateWithNumericTimes(): void
    {
        $hash = hash('sha256', 'invented');
        $session = Session::start(self::ID, $hash, self::NOW);
        $later = self::NOW + 900;
        $renewed = $session->renewed($later);
        $this->mock->append(new Result(['Attributes' => self::rawItem($hash, ['renewedAt' => $renewed->renewedAt, 'expiresAt' => $renewed->expiresAt])]));

        $result = $this->repository()->renew($session, $later);

        self::assertEquals($renewed, $result);
        $this->assertCommandNames('UpdateItem');
        self::assertSame('SET #renewedAt = :renewedAt, #expiresAt = :expiresAt', $this->commands[0]['UpdateExpression']);
        self::assertSame('attribute_exists(PK) AND #expiresAt > :now', $this->commands[0]['ConditionExpression']);
        self::assertSame(
            [':renewedAt' => ['N' => (string) $later], ':expiresAt' => ['N' => (string) $renewed->expiresAt], ':now' => ['N' => (string) $later]],
            $this->commands[0]['ExpressionAttributeValues'],
        );
        self::assertSame(['PK' => ['S' => 'SESSION#' . $hash], 'SK' => ['S' => 'SESSION']], $this->commands[0]['Key']);
    }

    public function testRenewOfAGoneSessionIsConditionFailed(): void
    {
        $this->mock->append(self::conditionFailed());

        $this->expectException(ConditionFailed::class);

        $this->repository()->renew(Session::start(self::ID, hash('sha256', 'invented'), self::NOW), self::NOW + 900);
    }

    public function testRenewThatReturnsNoAttributesIsACorruptItemFromUpdateItem(): void
    {
        $this->mock->append(new Result(['Attributes' => []]));

        try {
            $this->repository()->renew(Session::start(self::ID, hash('sha256', 'invented'), self::NOW), self::NOW + 900);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame(StorageFailure::CORRUPT_ITEM, $failure->awsErrorCode);
            self::assertSame('UpdateItem', $failure->operation);
        }
    }

    public function testRenewUnderThrottlingIsAStorageFailure(): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->mock->append(self::failure('ThrottlingException'));
        }

        try {
            $this->repository()->renew(Session::start(self::ID, hash('sha256', 'invented'), self::NOW), self::NOW + 900);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('ThrottlingException', $failure->awsErrorCode);
        }
    }

    public function testDeleteSendsTheSessionKey(): void
    {
        $hash = hash('sha256', 'invented');
        $this->mock->append(new Result([]));

        $this->repository()->delete($hash);

        $this->assertCommandNames('DeleteItem');
        self::assertSame(['PK' => ['S' => 'SESSION#' . $hash], 'SK' => ['S' => 'SESSION']], $this->commands[0]['Key']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHashes(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abc'];
        yield 'upper case' => [strtoupper(hash('sha256', 'invented'))];
        yield 'a cookie value' => [str_repeat('A', SessionToken::LENGTH)];
        yield 'trailing newline' => [hash('sha256', 'invented') . "\n"];
    }

    #[DataProvider('invalidHashes')]
    public function testDeleteFindAndRenewRefuseAnInvalidHashAndSendNothing(string $hash): void
    {
        $repository = $this->repository();
        $calls = [
            static fn() => $repository->delete($hash),
            static fn() => $repository->find($hash, self::NOW),
            static fn() => $repository->renew(new Session(self::ID, $hash, self::NOW, self::NOW, self::NOW + 100, self::NOW + 50), self::NOW),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException $refused) {
                if ($hash !== '') {
                    self::assertStringNotContainsString($hash, $refused->getMessage());
                }
            }
        }
        self::assertSame([], $this->commands);
    }
}
