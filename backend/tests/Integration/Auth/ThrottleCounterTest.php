<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\AuthKeys;
use App\Auth\Throttle\ThrottleCounter;
use App\Auth\Throttle\ThrottleDecision;
use App\Auth\Throttle\ThrottleRule;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Middleware;
use PHPUnit\Framework\TestCase;

/**
 * ThrottleCounter against DynamoDB Local, on a table of its own. Addresses are from the documentation ranges.
 */
final class ThrottleCounterTest extends TestCase
{
    private const IP = '192.0.2.7';
    private const START = '2026-10-16T12:00:00+00:00';
    private const START_TIMESTAMP = 1_792_152_000;

    private DynamoDbClient $client;
    private TemporaryTable $table;
    private ItemStore $store;
    private FrozenClock $clock;
    private ThrottleCounter $counter;
    private ThrottleRule $rule;

    protected function setUp(): void
    {
        $this->client = LocalAws::fromGlobals()->dynamoDb();
        $this->table = TemporaryTable::create($this->client);
        $this->store = new ItemStore($this->client, $this->table->name);
        $this->clock = new FrozenClock(self::START);
        $this->counter = new ThrottleCounter($this->store, $this->clock);
        $this->rule = new ThrottleRule(AuthKeys::LOGIN_IP, 3, 900);
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * @return array<string, mixed>|null
     *
     * @phpstan-impure
     */
    private function stored(): ?array
    {
        return $this->store->getItem(AuthKeys::throttle(AuthKeys::LOGIN_IP, self::IP));
    }

    private function seedExpired(int $attempts, int $secondsAgo = 5): void
    {
        $this->store->putItem(AuthKeys::throttle(AuthKeys::LOGIN_IP, self::IP) + ['attempts' => $attempts, 'expiresAt' => $this->now() - $secondsAgo]);
    }

    public function testTheFirstHitCreatesTheBucketWithOneAttemptAndTheEndOfTheWindow(): void
    {
        $decision = $this->counter->hit($this->rule, self::IP)->decision;

        self::assertTrue($decision->isAllowed);
        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(1, $item['attempts']);
        self::assertSame($this->now() + 900, $item['expiresAt']);
    }

    public function testExpiresAtIsStoredAsANumberForTheTtl(): void
    {
        $this->counter->hit($this->rule, self::IP);

        $raw = $this->client->getItem([
            'TableName' => $this->table->name,
            'Key' => ['PK' => ['S' => 'THROTTLE#LOGIN-IP#' . self::IP], 'SK' => ['S' => 'THROTTLE']],
            'ConsistentRead' => true,
        ])->toArray();

        $stored = $raw['Item'];
        self::assertIsArray($stored);
        self::assertSame(['N'], array_keys((array) $stored['expiresAt']));
        self::assertSame(['N'], array_keys((array) $stored['attempts']));
    }

    public function testTheWindowDoesNotSlideWithLaterHits(): void
    {
        $this->counter->hit($this->rule, self::IP);
        $end = $this->now() + 900;
        $this->clock->advance(400);

        $this->counter->hit($this->rule, self::IP);

        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(2, $item['attempts']);
        self::assertSame($end, $item['expiresAt']);
    }

    public function testTheLimitPassesAndTheNextHitIsBlockedWithTheTimeLeft(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            self::assertTrue($this->counter->hit($this->rule, self::IP)->decision->isAllowed, 'hit ' . $i);
        }
        $this->clock->advance(100);

        $blocked = $this->counter->hit($this->rule, self::IP)->decision;

        self::assertFalse($blocked->isAllowed);
        self::assertSame(800, $blocked->retryAfterSeconds);
    }

    public function testBlockedHitsKeepCountingWithoutExtendingTheWindow(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->counter->hit($this->rule, self::IP);
        }

        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(5, $item['attempts']);
        self::assertSame($this->now() + 900, $item['expiresAt']);
    }

    public function testOnTheLastSecondOfTheWindowRetryAfterIsOne(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->counter->hit($this->rule, self::IP);
        }
        $this->clock->advance(899);

        $blocked = $this->counter->hit($this->rule, self::IP)->decision;

        self::assertFalse($blocked->isAllowed);
        self::assertSame(1, $blocked->retryAfterSeconds);
    }

    public function testAfterTheWindowWithTheItemStillThereTheCountResetsToOne(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->counter->hit($this->rule, self::IP);
        }
        $this->clock->advance(900); // TTL has not deleted the item: it is still stored

        $decision = $this->counter->hit($this->rule, self::IP)->decision;

        self::assertTrue($decision->isAllowed);
        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(1, $item['attempts']);
        self::assertSame($this->now() + 900, $item['expiresAt']);
    }

    public function testAnExpiredItemIsReadAsExpiredWhateverItsCount(): void
    {
        $this->seedExpired(50, 1);

        $decision = $this->counter->hit($this->rule, self::IP)->decision;

        self::assertTrue($decision->isAllowed);
        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(1, $item['attempts']);
    }

    /**
     * Hits the bucket from a "loser" counter whose conditional reset-put is preceded, exactly once, by a whole hit of
     * a second counter (a second request on its own client): the winner resets the bucket first, the loser's put
     * fails its condition and it retries the increment once.
     *
     * @return array{ThrottleDecision, ThrottleDecision} the winner's and the loser's decision
     */
    private function hitWithAWinnerInterleavedBeforeTheReset(ThrottleRule $rule): array
    {
        $other = new ThrottleCounter(new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $this->table->name), $this->clock);
        $winner = null;
        $interleaved = 0;
        $loserClient = LocalAws::fromGlobals()->dynamoDb();
        $loserClient->getHandlerList()->appendInit(
            Middleware::tap(function (CommandInterface $command) use ($other, $rule, &$winner, &$interleaved): void {
                if ($command->getName() === 'PutItem' && $interleaved === 0) {
                    ++$interleaved;
                    $winner = $other->hit($rule, self::IP)->decision;
                }
            }),
            'interleave-reset',
        );
        $loser = new ThrottleCounter(new ItemStore($loserClient, $this->table->name), $this->clock);

        $loserDecision = $loser->hit($rule, self::IP)->decision;

        self::assertSame(1, $interleaved, 'the interleaving happened exactly once');
        self::assertNotNull($winner);

        return [$winner, $loserDecision];
    }

    public function testResetRaceTwoRequestsResettingAnExpiredBucketCountTwoNotOne(): void
    {
        $this->seedExpired(7);

        [$winner, $loser] = $this->hitWithAWinnerInterleavedBeforeTheReset($this->rule);

        // Without the retry the second request would be lost (total 1).
        self::assertTrue($winner->isAllowed);
        self::assertTrue($loser->isAllowed);
        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(2, $item['attempts']);
        self::assertSame($this->now() + 900, $item['expiresAt']);
    }

    public function testResetRaceCountsTowardTheLimit(): void
    {
        $this->seedExpired(7);

        [$winner, $loser] = $this->hitWithAWinnerInterleavedBeforeTheReset(new ThrottleRule(AuthKeys::LOGIN_IP, 1, 900));

        self::assertTrue($winner->isAllowed);
        self::assertFalse($loser->isAllowed, 'with a limit of 1 the second request of the same window is over it');
        self::assertSame(900, $loser->retryAfterSeconds);
    }

    public function testABucketWithoutExpiresAtIsResetToOneInAFreshWindow(): void
    {
        $this->store->putItem(AuthKeys::throttle(AuthKeys::LOGIN_IP, self::IP) + ['attempts' => 9]);

        $decision = $this->counter->hit($this->rule, self::IP)->decision;

        self::assertTrue($decision->isAllowed);
        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(1, $item['attempts']);
        self::assertSame($this->now() + 900, $item['expiresAt']);
    }

    public function testABucketWithExpiresAtStoredAsAStringIsResetToOneInAFreshWindow(): void
    {
        $this->store->putItem(AuthKeys::throttle(AuthKeys::LOGIN_IP, self::IP) + ['attempts' => 9, 'expiresAt' => 'soon']);

        $decision = $this->counter->hit($this->rule, self::IP)->decision;

        self::assertTrue($decision->isAllowed);
        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(1, $item['attempts']);
        self::assertSame($this->now() + 900, $item['expiresAt']);
    }

    public function testABucketWithAFarFutureExpiresAtFailsClosedAsCorrupt(): void
    {
        $this->store->putItem(AuthKeys::throttle(AuthKeys::LOGIN_IP, self::IP) + ['attempts' => 1, 'expiresAt' => $this->now() + 900 + 301]);

        try {
            $this->counter->hit($this->rule, self::IP);
            self::fail('Expected a StorageFailure.');
        } catch (StorageFailure $failure) {
            self::assertSame('CorruptItem', $failure->awsErrorCode);
        }
    }

    public function testHitReportsTheEndOfTheWindowItCounted(): void
    {
        $first = $this->counter->hit($this->rule, self::IP);
        $this->clock->advance(400);
        $second = $this->counter->hit($this->rule, self::IP);

        self::assertSame(self::START_TIMESTAMP + 900, $first->windowExpiresAt);
        self::assertSame(self::START_TIMESTAMP + 900, $second->windowExpiresAt);
    }

    public function testTenHitsFromSeparateClientsAllowOnlyTheLimit(): void
    {
        $allowed = 0;
        for ($i = 0; $i < 10; ++$i) {
            $counter = new ThrottleCounter(new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $this->table->name), $this->clock);
            $allowed += $counter->hit($this->rule, self::IP)->decision->isAllowed ? 1 : 0;
        }

        self::assertSame(3, $allowed);
        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(10, $item['attempts']);
    }

    public function testRefundTakesOffOneAttempt(): void
    {
        $this->counter->hit($this->rule, self::IP);
        $window = $this->counter->hit($this->rule, self::IP)->windowExpiresAt;

        $this->counter->refund($this->rule, self::IP, $window);

        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(1, $item['attempts']);
    }

    public function testRefundNeverGoesBelowZero(): void
    {
        $window = $this->counter->hit($this->rule, self::IP)->windowExpiresAt;

        // A repeated refund (an SDK retry) in the same window: the `> 0` bound stops it at zero. Within the window a
        // double decrement of a bucket above zero is possible, but at most it gives one attempt back.
        $this->counter->refund($this->rule, self::IP, $window);
        $this->counter->refund($this->rule, self::IP, $window);
        $this->counter->refund($this->rule, self::IP, $window);

        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(0, $item['attempts']);
    }

    public function testRefundOfAMissingBucketCreatesNothing(): void
    {
        $this->counter->refund($this->rule, self::IP, $this->now() + 900);

        self::assertNull($this->stored());
    }

    public function testRefundWithTheWindowOfAnExpiredBucketsSuccessorLeavesItAlone(): void
    {
        $this->seedExpired(4);

        $this->counter->refund($this->rule, self::IP, $this->now() + 900);

        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(4, $item['attempts']);
    }

    public function testARefundAfterTheWindowRolledOverChangesNothing(): void
    {
        $oldWindow = $this->counter->hit($this->rule, self::IP)->windowExpiresAt;
        $this->clock->advance(900); // the old window has ended; the next hit starts a new one
        $this->counter->hit($this->rule, self::IP);
        $this->counter->hit($this->rule, self::IP);

        $this->counter->refund($this->rule, self::IP, $oldWindow);

        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(2, $item['attempts']);
        self::assertSame($this->now() + 900, $item['expiresAt']);
    }

    public function testAfterARefundTheFreedAttemptCanBeUsedAgain(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->counter->hit($this->rule, self::IP);
        }
        $this->counter->refund($this->rule, self::IP, $this->now() + 900);

        self::assertTrue($this->counter->hit($this->rule, self::IP)->decision->isAllowed);
        self::assertFalse($this->counter->hit($this->rule, self::IP)->decision->isAllowed);
    }

    public function testDeleteRemovesTheBucketAndTheNextHitStartsAtOne(): void
    {
        $this->counter->hit($this->rule, self::IP);
        $this->counter->hit($this->rule, self::IP);

        $this->counter->delete($this->rule, self::IP);

        self::assertNull($this->stored());
        $this->counter->hit($this->rule, self::IP);
        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(1, $item['attempts']);
    }

    public function testDeleteOfAMissingBucketIsFine(): void
    {
        $this->counter->delete($this->rule, self::IP);

        self::assertNull($this->stored());
    }

    public function testBucketsOfDifferentKeysAndScopesAreIndependent(): void
    {
        $this->counter->hit($this->rule, self::IP);
        $this->counter->hit(new ThrottleRule(AuthKeys::REGISTER_IP, 5, 3600), self::IP);
        $this->counter->hit($this->rule, '192.0.2.8');

        $item = $this->stored();
        self::assertNotNull($item);
        self::assertSame(1, $item['attempts']);
        $register = $this->store->getItem(AuthKeys::throttle(AuthKeys::REGISTER_IP, self::IP));
        self::assertNotNull($register);
        self::assertSame($this->now() + 3600, $register['expiresAt']);
    }
}
