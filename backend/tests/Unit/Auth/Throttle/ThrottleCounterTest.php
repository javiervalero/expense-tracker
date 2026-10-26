<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Throttle;

use App\Auth\AuthKeys;
use App\Auth\Throttle\ThrottleCounter;
use App\Auth\Throttle\ThrottleRule;
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
 * The request payloads and error paths of ThrottleCounter against scripted DynamoDB answers; the real behaviour
 * (counts, windows, the reset race) is in the integration test. Keys and times are invented.
 */
final class ThrottleCounterTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const IP = '192.0.2.7';
    private const WINDOW = 900;

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

    private function counter(): ThrottleCounter
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;

            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };

        return new ThrottleCounter(new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName), $this->clock);
    }

    private static function rule(): ThrottleRule
    {
        return ThrottleRule::loginAddress();
    }

    private static function conditionFailed(): DynamoDbException
    {
        return new DynamoDbException('failed', new \Aws\Command('UpdateItem'), ['code' => 'ConditionalCheckFailedException']);
    }

    private static function failure(string $code): DynamoDbException
    {
        return new DynamoDbException('failed', new \Aws\Command('UpdateItem'), ['code' => $code]);
    }

    /**
     * @param array<string, mixed> $override null removes the attribute; numbers are given as int
     *
     * @return Result<string, mixed>
     */
    private static function stored(int $attempts, ?int $expiresAt = null, array $override = []): Result
    {
        $fields = $override + [
            'PK' => 'THROTTLE#LOGIN-IP#' . self::IP,
            'SK' => 'THROTTLE',
            'attempts' => $attempts,
            'expiresAt' => $expiresAt ?? self::NOW + self::WINDOW,
        ];
        $raw = [];
        foreach ($fields as $name => $value) {
            if ($value === null) {
                continue;
            }
            $raw[$name] = is_int($value) ? ['N' => (string) $value] : ['S' => is_string($value) ? $value : throw new \LogicException('Use a string or an int.')];
        }

        return new Result(['Attributes' => $raw]);
    }

    /**
     * @return array<string, mixed>
     */
    private function command(int $index): array
    {
        /** @var array<string, mixed> */
        return $this->commands[$index]->toArray();
    }

    private function assertCommandNames(string ...$names): void
    {
        self::assertSame($names, array_map(static fn(CommandInterface $command): string => $command->getName(), $this->commands));
    }

    public function testHitSendsOneAtomicConditionalAddAndAllowsTheFirstAttempt(): void
    {
        $this->mock->append(self::stored(1));

        $decision = $this->counter()->hit(self::rule(), self::IP)->decision;

        self::assertTrue($decision->isAllowed);
        $this->assertCommandNames('UpdateItem');
        $command = $this->command(0);
        self::assertSame('expense-tracker-test', $command['TableName']);
        self::assertSame(['PK' => ['S' => 'THROTTLE#LOGIN-IP#' . self::IP], 'SK' => ['S' => 'THROTTLE']], $command['Key']);
        self::assertSame('SET #expiresAt = if_not_exists(#expiresAt, :expires) ADD #attempts :one', $command['UpdateExpression']);
        self::assertSame('attribute_not_exists(PK) OR #expiresAt > :now', $command['ConditionExpression']);
        self::assertSame(['#attempts' => 'attempts', '#expiresAt' => 'expiresAt'], $command['ExpressionAttributeNames']);
        self::assertSame(
            [':one' => ['N' => '1'], ':now' => ['N' => (string) self::NOW], ':expires' => ['N' => (string) (self::NOW + self::WINDOW)]],
            $command['ExpressionAttributeValues'],
        );
    }

    public function testHitReportsTheEndOfTheWindowItCounted(): void
    {
        $this->mock->append(self::stored(1, self::NOW + 321));

        $hit = $this->counter()->hit(self::rule(), self::IP);

        self::assertSame(self::NOW + 321, $hit->windowExpiresAt);
    }

    public function testTheHitAtTheLimitIsAllowedAndTheNextIsBlockedWithTheTimeLeftInTheWindow(): void
    {
        $this->mock->append(self::stored(30, self::NOW + 100));
        $this->mock->append(self::stored(31, self::NOW + 100));

        $counter = $this->counter();
        $atLimit = $counter->hit(self::rule(), self::IP)->decision;
        $over = $counter->hit(self::rule(), self::IP)->decision;

        self::assertTrue($atLimit->isAllowed);
        self::assertFalse($over->isAllowed);
        self::assertSame(100, $over->retryAfterSeconds);
    }

    public function testRetryAfterIsAtLeastOneSecondEvenOnTheLastSecondOfTheWindow(): void
    {
        $this->mock->append(self::stored(99, self::NOW + 1));

        $decision = $this->counter()->hit(self::rule(), self::IP)->decision;

        self::assertFalse($decision->isAllowed);
        self::assertSame(1, $decision->retryAfterSeconds);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function windowEndsPastTheWindow(): iterable
    {
        yield 'a clock read one second later' => [1];
        yield 'the clock skew validated() still accepts' => [300];
    }

    #[DataProvider('windowEndsPastTheWindow')]
    public function testRetryAfterNeverExceedsTheWindowWhenTheWindowWasOpenedByARequestWithALaterClockRead(int $later): void
    {
        $this->mock->append(self::stored(31, self::NOW + self::WINDOW + $later));

        $hit = $this->counter()->hit(self::rule(), self::IP);

        self::assertFalse($hit->decision->isAllowed);
        self::assertSame(self::WINDOW, $hit->decision->retryAfterSeconds);
        // Only the advertised wait is capped: refunds match the stored window end exactly.
        self::assertSame(self::NOW + self::WINDOW + $later, $hit->windowExpiresAt);
    }

    public function testRetryAfterIsTheFullWindowWhenTheWindowWasJustOpened(): void
    {
        $this->mock->append(self::stored(31, self::NOW + self::WINDOW));

        $decision = $this->counter()->hit(self::rule(), self::IP)->decision;

        self::assertFalse($decision->isAllowed);
        self::assertSame(self::WINDOW, $decision->retryAfterSeconds);
    }

    public function testRetryAfterInsideTheWindowIsTheTimeLeft(): void
    {
        $this->mock->append(self::stored(31, self::NOW + self::WINDOW - 400));

        $decision = $this->counter()->hit(self::rule(), self::IP)->decision;

        self::assertFalse($decision->isAllowed);
        self::assertSame(self::WINDOW - 400, $decision->retryAfterSeconds);
    }

    public function testAnExpiredWindowIsResetWithAConditionalPut(): void
    {
        $this->mock->append(self::conditionFailed());
        $this->mock->append(new Result([]));

        $decision = $this->counter()->hit(self::rule(), self::IP)->decision;

        self::assertTrue($decision->isAllowed);
        $this->assertCommandNames('UpdateItem', 'PutItem');
        $put = $this->command(1);
        self::assertSame(
            [
                'PK' => ['S' => 'THROTTLE#LOGIN-IP#' . self::IP],
                'SK' => ['S' => 'THROTTLE'],
                'attempts' => ['N' => '1'],
                'expiresAt' => ['N' => (string) (self::NOW + self::WINDOW)],
            ],
            $put['Item'],
        );
        self::assertSame('attribute_not_exists(PK) OR NOT (#expiresAt > :now)', $put['ConditionExpression']);
        self::assertSame(['#expiresAt' => 'expiresAt'], $put['ExpressionAttributeNames']);
        self::assertSame([':now' => ['N' => (string) self::NOW]], $put['ExpressionAttributeValues']);
    }

    public function testTheLoserOfAResetRaceRetriesTheIncrementOnce(): void
    {
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::stored(2));

        $decision = $this->counter()->hit(self::rule(), self::IP)->decision;

        self::assertTrue($decision->isAllowed);
        $this->assertCommandNames('UpdateItem', 'PutItem', 'UpdateItem');
    }

    public function testTheRetriedIncrementIsCountedAgainstTheLimit(): void
    {
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::stored(31, self::NOW + 50));

        $decision = $this->counter()->hit(self::rule(), self::IP)->decision;

        self::assertFalse($decision->isAllowed);
        self::assertSame(50, $decision->retryAfterSeconds);
    }

    public function testASecondFailedIncrementAfterTheResetIsAStorageFailureNotALoop(): void
    {
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::conditionFailed());
        $this->mock->append(self::conditionFailed());

        try {
            $this->counter()->hit(self::rule(), self::IP);
            self::fail('Expected a StorageFailure.');
        } catch (StorageFailure $failure) {
            self::assertSame('ThrottleRace', $failure->awsErrorCode);
        }
        $this->assertCommandNames('UpdateItem', 'PutItem', 'UpdateItem');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function overloadCodes(): iterable
    {
        yield 'provisioned throughput' => ['ProvisionedThroughputExceededException'];
        yield 'throttling' => ['ThrottlingException'];
        yield 'request limit' => ['RequestLimitExceeded'];
    }

    #[DataProvider('overloadCodes')]
    public function testThrottlingOnTheIncrementIsAStorageFailureWithTheCode(string $code): void
    {
        // The SDK retries 3 times before giving up; script all three answers.
        for ($i = 0; $i < 3; ++$i) {
            $this->mock->append(self::failure($code));
        }

        try {
            $this->counter()->hit(self::rule(), self::IP);
            self::fail('Expected a StorageFailure.');
        } catch (StorageFailure $failure) {
            self::assertSame($code, $failure->awsErrorCode);
            self::assertSame('UpdateItem', $failure->operation);
        }
    }

    public function testThrottlingOnTheResetPutIsAStorageFailure(): void
    {
        $this->mock->append(self::conditionFailed());
        for ($i = 0; $i < 3; ++$i) {
            $this->mock->append(self::failure('ThrottlingException'));
        }

        try {
            $this->counter()->hit(self::rule(), self::IP);
            self::fail('Expected a StorageFailure.');
        } catch (StorageFailure $failure) {
            self::assertSame('ThrottlingException', $failure->awsErrorCode);
            self::assertSame('PutItem', $failure->operation);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function corruptItems(): iterable
    {
        yield 'attempts missing' => [['attempts' => null]];
        yield 'attempts is a string' => [['attempts' => '3']];
        yield 'attempts is zero' => [['attempts' => 0]];
        yield 'attempts is negative' => [['attempts' => -2]];
        yield 'expiresAt missing' => [['expiresAt' => null]];
        yield 'expiresAt is a string' => [['expiresAt' => 'soon']];
        yield 'expiresAt is zero' => [['expiresAt' => 0]];
        yield 'expiresAt far beyond the window' => [['expiresAt' => self::NOW + 10 * 365 * 86400]];
        yield 'wrong partition key' => [['PK' => 'THROTTLE#LOGIN-IP#192.0.2.99']];
        yield 'wrong sort key' => [['SK' => 'SESSION']];
        yield 'partition key missing' => [['PK' => null]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('corruptItems')]
    public function testAnItemReadBackThatDoesNotValidateIsCorrupt(array $override): void
    {
        $this->mock->append(self::stored(3, null, $override));

        try {
            $this->counter()->hit(self::rule(), self::IP);
            self::fail('Expected a StorageFailure.');
        } catch (StorageFailure $failure) {
            self::assertSame('CorruptItem', $failure->awsErrorCode);
        }
    }

    public function testAnItemWithoutAttributesIsCorrupt(): void
    {
        $this->mock->append(new Result([]));

        $this->expectException(StorageFailure::class);
        $this->expectExceptionMessage('CorruptItem');

        $this->counter()->hit(self::rule(), self::IP);
    }

    public function testRefundSendsAGuardedDecrementNeverBelowZero(): void
    {
        $this->mock->append(new Result([]));

        $this->counter()->refund(self::rule(), self::IP, self::NOW + self::WINDOW);

        $this->assertCommandNames('UpdateItem');
        $command = $this->command(0);
        self::assertSame(['PK' => ['S' => 'THROTTLE#LOGIN-IP#' . self::IP], 'SK' => ['S' => 'THROTTLE']], $command['Key']);
        self::assertSame('ADD #attempts :minusOne', $command['UpdateExpression']);
        self::assertSame('attribute_exists(PK) AND #expiresAt = :countedWindow AND #attempts > :zero', $command['ConditionExpression']);
        self::assertSame(['#attempts' => 'attempts', '#expiresAt' => 'expiresAt'], $command['ExpressionAttributeNames']);
        self::assertSame(
            [':minusOne' => ['N' => '-1'], ':countedWindow' => ['N' => (string) (self::NOW + self::WINDOW)], ':zero' => ['N' => '0']],
            $command['ExpressionAttributeValues'],
        );
    }

    public function testRefundOfAMissingExpiredOrEmptyBucketIsANoOp(): void
    {
        $this->mock->append(self::conditionFailed());

        $this->counter()->refund(self::rule(), self::IP, self::NOW + self::WINDOW);

        $this->assertCommandNames('UpdateItem');
    }

    public function testRefundStorageFailureIsReported(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->mock->append(self::failure('ThrottlingException'));
        }

        $this->expectException(StorageFailure::class);

        $this->counter()->refund(self::rule(), self::IP, self::NOW + self::WINDOW);
    }

    public function testDeleteSendsAnUnconditionalDeleteOfTheBucket(): void
    {
        $this->mock->append(new Result([]));

        $this->counter()->delete(ThrottleRule::loginAccount(), hash('sha256', 'ana@example.com'));

        $this->assertCommandNames('DeleteItem');
        $command = $this->command(0);
        self::assertSame(
            ['PK' => ['S' => 'THROTTLE#LOGIN-EMAIL#' . hash('sha256', 'ana@example.com')], 'SK' => ['S' => 'THROTTLE']],
            $command['Key'],
        );
        self::assertArrayNotHasKey('ConditionExpression', $command);
    }

    public function testDeleteStorageFailureIsReported(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->mock->append(self::failure('ThrottlingException'));
        }

        $this->expectException(StorageFailure::class);

        $this->counter()->delete(self::rule(), self::IP);
    }

    public function testAKeyOfTheWrongShapeSendsNothing(): void
    {
        $counter = $this->counter();

        $calls = [
            static fn() => $counter->hit(ThrottleRule::loginAccount(), 'ana@example.com'),
            static fn() => $counter->refund(ThrottleRule::loginAccount(), 'ana@example.com', self::NOW + self::WINDOW),
            static fn() => $counter->delete(ThrottleRule::loginAccount(), 'ana@example.com'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }
        self::assertSame([], $this->commands);
    }

    public function testTheKeyOfARuleOfAnotherScopeIsRefusedToo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->counter()->hit(new ThrottleRule(AuthKeys::LOGIN_NET, 100, 900), self::IP);
    }
}
