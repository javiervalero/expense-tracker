<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\User;

use App\Auth\Email;
use App\Auth\Password\PasswordHasher;
use App\Auth\User\EmailTaken;
use App\Auth\User\User;
use App\Auth\User\UserRepository;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\Id\Uuid;
use App\Tests\Support\FrozenClock;
use Aws\CommandInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use DateTimeImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The branches of create() that need a scripted DynamoDB answer; the real behaviour is in the integration test.
 */
final class UserRepositoryTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_ID = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const HASH = '$argon2id$v=19$m=8,t=1,p=1$invented-salt$invented-hash-value';
    private const OTHER_HASH = '$argon2id$v=19$m=8,t=1,p=1$invented-salt$another-invented-value';

    private MockHandler $mock;

    /** @var list<CommandInterface> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->commands = [];
        $this->mock = new MockHandler();
    }

    private function repository(): UserRepository
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;

            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $client = DynamoDbClientFactory::create($settings, $recording);

        return new UserRepository(new ItemStore($client, $settings->tableName));
    }

    private static function user(): User
    {
        $email = Email::parse('ana@example.com');
        self::assertInstanceOf(Email::class, $email);

        return User::register(self::ID, $email, self::HASH, new FrozenClock());
    }

    /**
     * @param list<string> $codes
     */
    private static function cancelled(array $codes): DynamoDbException
    {
        $reasons = array_map(static fn(string $code): array => ['Code' => $code], $codes);

        return new DynamoDbException('cancelled', new \Aws\Command('TransactWriteItems'), ['code' => 'TransactionCanceledException', 'body' => ['CancellationReasons' => $reasons]]);
    }

    public function testCreateSendsOneTransactionWithBothConditionalPuts(): void
    {
        $this->mock->append(new Result([]));

        $this->repository()->create(self::user());

        self::assertCount(1, $this->commands);
        self::assertSame('TransactWriteItems', $this->commands[0]->getName());
        /** @var list<array{Put: array{ConditionExpression: string, Item: array<string, array{S: string}>}}> $items */
        $items = $this->commands[0]['TransactItems'];
        self::assertCount(2, $items);
        foreach ($items as $item) {
            self::assertSame('attribute_not_exists(PK)', $item['Put']['ConditionExpression']);
        }
        self::assertSame('USER#' . self::ID, $items[0]['Put']['Item']['PK']['S']);
        self::assertSame('EMAIL#ana@example.com', $items[1]['Put']['Item']['PK']['S']);
        self::assertSame(self::ID, $items[1]['Put']['Item']['userId']['S']);
        self::assertSame('2026-10-16T12:00:00Z', $items[0]['Put']['Item']['createdAt']['S']);
    }

    public function testAThrottledTransactionIsRethrownWithoutRereadingTheLock(): void
    {
        $this->mock->append(self::cancelled(['ThrottlingError', 'None']));

        try {
            $this->repository()->create(self::user());
            self::fail('expected TransactionCancelled');
        } catch (TransactionCancelled $cancelled) {
            self::assertSame(['ThrottlingError', 'None'], $cancelled->reasons);
        }
        self::assertCount(1, $this->commands);
    }

    public function testALockHeldByAnotherUserIsEmailTaken(): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(new Result(['Item' => ['PK' => ['S' => 'EMAIL#ana@example.com'], 'SK' => ['S' => 'EMAIL'], 'userId' => ['S' => self::OTHER_ID]]]));

        $this->expectException(EmailTaken::class);

        $this->repository()->create(self::user());
    }

    /**
     * @return Result<string, mixed>
     */
    private static function lockHeldBy(string $holder): Result
    {
        return new Result(['Item' => ['PK' => ['S' => 'EMAIL#ana@example.com'], 'SK' => ['S' => 'EMAIL'], 'userId' => ['S' => $holder]]]);
    }

    public function testALockHeldByTheSameUserWithTheSameStoredUserIsASuccess(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'ConditionalCheckFailed']));
        $this->mock->append(self::lockHeldBy(self::ID));
        $this->mock->append(self::userResult());

        $this->repository()->create(self::user());

        self::assertCount(3, $this->commands);
        self::assertSame('GetItem', $this->commands[1]->getName());
        self::assertSame('GetItem', $this->commands[2]->getName());
        /** @var array{PK: array{S: string}} $key */
        $key = $this->commands[2]['Key'];
        self::assertSame('USER#' . self::ID, $key['PK']['S']);
    }

    /**
     * A19: our own earlier attempt may have gone through, so the same id on the lock only counts when the stored user
     * is the one being created. A different user that happens to reuse the id must not be reported as success.
     *
     * @return iterable<string, array{Result<string, mixed>}>
     */
    public static function storedUsersThatAreNotTheOneBeingCreated(): iterable
    {
        yield 'no user item' => [new Result([])];
        yield 'another creation time' => [self::userResult(['createdAt' => ['S' => '2026-10-16T12:00:01Z']])];
        yield 'another hash' => [self::userResult(['passwordHash' => ['S' => self::OTHER_HASH]])];
        yield 'hash of the wrong type' => [self::userResult(['passwordHash' => ['N' => '5']])];
        yield 'creation time of the wrong type' => [self::userResult(['createdAt' => ['N' => '5']])];
    }

    /**
     * @param Result<string, mixed> $stored
     */
    #[DataProvider('storedUsersThatAreNotTheOneBeingCreated')]
    public function testALockHeldByTheSameIdButAnotherStoredUserRethrowsTheCancellation(Result $stored): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'ConditionalCheckFailed']));
        $this->mock->append(self::lockHeldBy(self::ID));
        $this->mock->append($stored);

        $this->expectException(TransactionCancelled::class);

        $this->repository()->create(self::user());
    }

    public function testAClockWithAnOffsetIsStoredAsUtc(): void
    {
        $this->mock->append(new Result([]));
        $email = Email::parse('ana@example.com');
        self::assertInstanceOf(Email::class, $email);

        $this->repository()->create(new User(self::ID, $email, self::HASH, new DateTimeImmutable('2026-10-16T14:00:00+02:00')));

        /** @var list<array{Put: array{Item: array<string, array{S: string}>}}> $items */
        $items = $this->commands[0]['TransactItems'];
        self::assertSame('2026-10-16T12:00:00Z', $items[0]['Put']['Item']['createdAt']['S']);
    }

    public function testAFailedConditionWithNoLockToExplainItIsRethrown(): void
    {
        $this->mock->append(self::cancelled(['ConditionalCheckFailed', 'None']));
        $this->mock->append(new Result([]));

        $this->expectException(TransactionCancelled::class);

        $this->repository()->create(self::user());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unusableLockHolders(): iterable
    {
        yield 'a number' => [['N' => '5']];
        yield 'not a uuid' => [['S' => 'USER#x']];
        yield 'an upper-case uuid' => [['S' => '3F2B8C1E-5D4A-4B7E-9A60-1C2D3E4F5A6B']];
        yield 'an empty string' => [['S' => '']];
    }

    /**
     * @param array<string, string> $holder
     */
    #[DataProvider('unusableLockHolders')]
    public function testALockWhoseHolderIsNotAUuidIsACorruptItemNotEmailTaken(array $holder): void
    {
        $this->mock->append(self::cancelled(['None', 'ConditionalCheckFailed']));
        $this->mock->append(new Result(['Item' => ['PK' => ['S' => 'EMAIL#ana@example.com'], 'SK' => ['S' => 'EMAIL'], 'userId' => $holder]]));

        try {
            $this->repository()->create(self::user());
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('CorruptItem', $failure->awsErrorCode);
        }
    }

    /**
     * @param array<string, array<string, string>> $overrides
     *
     * @return Result<string, mixed>
     */
    private static function userResult(array $overrides = []): Result
    {
        return new Result(['Item' => $overrides + [
            'PK' => ['S' => 'USER#' . self::ID],
            'SK' => ['S' => 'PROFILE'],
            'userId' => ['S' => self::ID],
            'email' => ['S' => 'ana@example.com'],
            'passwordHash' => ['S' => self::HASH],
            'createdAt' => ['S' => '2026-10-16T12:00:00Z'],
        ]]);
    }

    public function testFindByIdBuildsTheUserFromAValidItem(): void
    {
        $this->mock->append(self::userResult());

        $user = $this->repository()->findById(self::ID);

        self::assertSame(self::HASH, $user?->passwordHash());
        self::assertSame('2026-10-16T12:00:00+00:00', $user->createdAt->format('c'));
    }

    public function testFindByIdOfAnUnknownUserIsNull(): void
    {
        $this->mock->append(new Result([]));

        self::assertNull($this->repository()->findById(self::ID));
    }

    public function testFindByIdRefusesAnItemWithAWronglyTypedField(): void
    {
        $this->mock->append(self::userResult(['passwordHash' => ['N' => '5']]));

        try {
            $this->repository()->findById(self::ID);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('CorruptItem', $failure->awsErrorCode);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storedHashesThatAreNotArgon2id(): iterable
    {
        yield 'empty' => [''];
        yield 'plain text' => ['plain-invented-secret'];
        yield 'another algorithm' => ['$2y$10$invented-bcrypt-looking-value'];
    }

    #[DataProvider('storedHashesThatAreNotArgon2id')]
    public function testFindByIdRefusesAStoredHashThatIsNotArgon2id(string $hash): void
    {
        $this->mock->append(self::userResult(['passwordHash' => ['S' => $hash]]));

        try {
            $this->repository()->findById(self::ID);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('CorruptItem', $failure->awsErrorCode);
        }
    }

    #[DataProvider('storedHashesThatAreNotArgon2id')]
    public function testUpdatePasswordHashRefusesAHashThatIsNotArgon2idAndSendsNothing(string $hash): void
    {
        try {
            $this->repository()->updatePasswordHash(self::ID, self::HASH, $hash);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $refused) {
            if ($hash !== '') {
                self::assertStringNotContainsString($hash, $refused->getMessage());
            }
        }
        self::assertSame([], $this->commands);
    }

    public function testFindByEmailFollowsTheLockToTheUser(): void
    {
        $this->mock->append(new Result(['Item' => ['PK' => ['S' => 'EMAIL#ana@example.com'], 'SK' => ['S' => 'EMAIL'], 'userId' => ['S' => self::ID]]]));
        $this->mock->append(self::userResult());
        $email = Email::parse('ana@example.com');
        self::assertInstanceOf(Email::class, $email);

        self::assertSame(self::ID, $this->repository()->findByEmail($email)?->userId);
    }

    public function testFindByEmailOfAnUnknownAddressIsNull(): void
    {
        $this->mock->append(new Result([]));
        $email = Email::parse('ana@example.com');
        self::assertInstanceOf(Email::class, $email);

        self::assertNull($this->repository()->findByEmail($email));
    }

    public function testFindByEmailRefusesALockWithoutAUsableUser(): void
    {
        $this->mock->append(new Result(['Item' => ['PK' => ['S' => 'EMAIL#ana@example.com'], 'SK' => ['S' => 'EMAIL'], 'userId' => ['S' => self::ID]]]));
        $this->mock->append(new Result([]));
        $email = Email::parse('ana@example.com');
        self::assertInstanceOf(Email::class, $email);

        $this->expectException(StorageFailure::class);

        $this->repository()->findByEmail($email);
    }

    public function testUpdatePasswordHashIsConditionalOnTheUserExistingAndOnTheHashItReplaces(): void
    {
        $this->mock->append(new Result(['Attributes' => []]));

        $this->repository()->updatePasswordHash(self::ID, self::HASH, self::OTHER_HASH);

        self::assertSame('UpdateItem', $this->commands[0]->getName());
        self::assertSame('attribute_exists(PK) AND #passwordHash = :expected', $this->commands[0]['ConditionExpression']);
        self::assertSame('SET #passwordHash = :passwordHash', $this->commands[0]['UpdateExpression']);
        self::assertSame(['#passwordHash' => 'passwordHash'], $this->commands[0]['ExpressionAttributeNames']);
        $values = $this->commands[0]['ExpressionAttributeValues'];
        self::assertIsArray($values);
        self::assertSame(['S' => self::HASH], $values[':expected'] ?? null);
        self::assertSame(['S' => self::OTHER_HASH], $values[':passwordHash'] ?? null);
    }

    public function testAStaleExpectedHashIsAConditionFailureForTheCaller(): void
    {
        $this->mock->append(new DynamoDbException('stale', new \Aws\Command('UpdateItem'), ['code' => 'ConditionalCheckFailedException']));

        $this->expectException(ConditionFailed::class);

        $this->repository()->updatePasswordHash(self::ID, self::HASH, self::OTHER_HASH);
    }

    #[DataProvider('storedHashesThatAreNotArgon2id')]
    public function testUpdatePasswordHashRefusesAnExpectedHashThatIsNotArgon2idAndSendsNothing(string $expected): void
    {
        try {
            $this->repository()->updatePasswordHash(self::ID, $expected, self::OTHER_HASH);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $refused) {
            if ($expected !== '') {
                self::assertStringNotContainsString($expected, $refused->getMessage());
            }
        }

        self::assertSame([], $this->commands);
    }

    public function testUpdatePasswordHashRefusesTheDummyHashAndSendsNothing(): void
    {
        try {
            $this->repository()->updatePasswordHash(self::ID, self::HASH, PasswordHasher::DUMMY_HASH);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
        }

        self::assertSame([], $this->commands);
    }

    public function testAStoredUserWithTheDummyHashIsACorruptItem(): void
    {
        $this->mock->append(self::userResult(['passwordHash' => ['S' => PasswordHasher::DUMMY_HASH]]));

        try {
            $this->repository()->findById(self::ID);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('CorruptItem', $failure->awsErrorCode);
        }
    }

    public function testReadDummyUserSendsOneGetItemWithTheSameParametersAsFindByIdAndReturnsNothing(): void
    {
        $this->mock->append(new Result([]));
        $this->mock->append(new Result([]));

        $this->repository()->readDummyUser();
        $this->repository()->findById(self::ID);

        self::assertCount(2, $this->commands);
        self::assertSame('GetItem', $this->commands[0]->getName());
        $dummy = $this->commands[0]->toArray();
        $real = $this->commands[1]->toArray();
        self::assertSame(['PK' => ['S' => 'USER#' . UserRepository::DUMMY_USER_ID], 'SK' => ['S' => 'PROFILE']], $dummy['Key'] ?? null);
        self::assertTrue(Uuid::isValid(UserRepository::DUMMY_USER_ID));
        unset($real['Key'], $dummy['Key']);
        self::assertSame($real, $dummy, 'the dummy read must differ from a real one only by its key');
    }

    public function testReadDummyUserReturnsNothingAtAll(): void
    {
        $method = new \ReflectionMethod(UserRepository::class, 'readDummyUser');

        self::assertSame('void', (string) $method->getReturnType());
    }

    public function testReadDummyUserNeverYieldsAnAccountEvenIfTheItemExists(): void
    {
        $this->mock->append(self::userResult(['userId' => ['S' => UserRepository::DUMMY_USER_ID]]));

        $this->repository()->readDummyUser();

        self::assertCount(1, $this->commands);
    }

    public function testReadDummyUserPropagatesAStorageFailure(): void
    {
        $this->mock->append(new DynamoDbException('bad', new \Aws\Command('GetItem'), ['code' => 'ValidationException']));

        $this->expectException(StorageFailure::class);

        $this->repository()->readDummyUser();
    }
}
