<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\AuthKeys;
use App\Auth\Session\Session;
use App\Auth\Session\SessionRepository;
use App\Auth\Session\SessionToken;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SessionRepository against DynamoDB Local, on a table of its own. Ids are invented.
 */
final class SessionRepositoryTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_ID = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';

    private TemporaryTable $table;
    private ItemStore $store;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $this->table = TemporaryTable::create($client);
        $this->store = new ItemStore($client, $this->table->name);
        $this->clock = new FrozenClock('2026-10-16T12:00:00+00:00');
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    /**
     * @param list<SessionToken> $tokens handed out in order by the repository's token generator
     */
    private function repository(array $tokens = []): SessionRepository
    {
        if ($tokens === []) {
            return new SessionRepository($this->store, $this->clock);
        }

        return new SessionRepository($this->store, $this->clock, static function () use (&$tokens): SessionToken {
            $next = array_shift($tokens);
            if ($next === null) {
                throw new \LogicException('The test ran out of tokens.');
            }

            return $next;
        });
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    public function testCreateThenFindReturnsTheSession(): void
    {
        $repository = $this->repository();

        $token = $repository->create(self::ID);
        $session = $repository->find($token->hash(), $this->now());

        self::assertNotNull($session);
        self::assertSame(self::ID, $session->userId);
        self::assertSame($token->hash(), $session->tokenHash);
        self::assertSame($this->now(), $session->createdAt);
        self::assertSame($this->now(), $session->renewedAt);
        self::assertSame($this->now() + 86_400, $session->expiresAt);
        self::assertSame($this->now() + 604_800, $session->absoluteExpiresAt);
    }

    public function testTheStoredItemHasExactlyTheDocumentedAttributesAndAllTimesAreNumbers(): void
    {
        $token = $this->repository()->create(self::ID);

        $item = $this->store->getItem(AuthKeys::session($token->hash()));

        self::assertNotNull($item);
        ksort($item);
        self::assertSame([
            'PK' => 'SESSION#' . $token->hash(),
            'SK' => 'SESSION',
            'absoluteExpiresAt' => $this->now() + 604_800,
            'createdAt' => $this->now(),
            'expiresAt' => $this->now() + 86_400,
            'renewedAt' => $this->now(),
            'userId' => self::ID,
        ], $item);
        $raw = $this->rawItem($token->hash());
        foreach (['createdAt', 'renewedAt', 'expiresAt', 'absoluteExpiresAt'] as $field) {
            self::assertSame(['N'], array_keys($raw[$field]), $field);
        }
        self::assertStringNotContainsString($token->cookieValue(), (string) json_encode($raw));
    }

    public function testCreateRefusesAnInvalidUserIdAndWritesNothing(): void
    {
        try {
            $this->repository()->create('not-a-uuid');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->allItems());
    }

    public function testFindReturnsNullForAnUnknownHash(): void
    {
        self::assertNull($this->repository()->find(hash('sha256', 'invented'), $this->now()));
    }

    public function testFindIgnoresAnItemWhoseIdleExpiryHasPassedEvenThoughTtlHasNotDeletedIt(): void
    {
        $repository = $this->repository();
        $token = $repository->create(self::ID);

        self::assertNotNull($repository->find($token->hash(), $this->now() + 86_399));
        self::assertNull($repository->find($token->hash(), $this->now() + 86_400));
    }

    public function testFindIgnoresAnItemPastItsAbsoluteExpiry(): void
    {
        $hash = hash('sha256', 'invented-absolute');
        $this->put($hash, ['createdAt' => $this->now() - 1000, 'renewedAt' => $this->now() - 1000, 'expiresAt' => $this->now(), 'absoluteExpiresAt' => $this->now()]);

        self::assertNull($this->repository()->find($hash, $this->now()));
    }

    public function testAnItemWithAnAbsoluteExpiryBeyondTheCurrentCapEndsAtTheCap(): void
    {
        $hash = hash('sha256', 'invented-lowered-cap');
        $this->put($hash, ['absoluteExpiresAt' => $this->now() + 604_800 + 5000]);

        $session = $this->repository()->find($hash, $this->now());

        self::assertSame($this->now() + 604_800, $session?->absoluteExpiresAt);
        self::assertNull($this->repository()->find($hash, $this->now() + 604_800));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function corruptFields(): iterable
    {
        yield 'userId missing' => [['userId' => null]];
        yield 'userId not a uuid' => [['userId' => 'USER#x']];
        yield 'userId a number' => [['userId' => 5]];
        yield 'userId upper case' => [['userId' => strtoupper(self::ID)]];
        yield 'createdAt a string' => [['createdAt' => '1800000000']];
        yield 'createdAt missing' => [['createdAt' => null]];
        yield 'renewedAt a string' => [['renewedAt' => 'x']];
        yield 'renewedAt missing' => [['renewedAt' => null]];
        yield 'expiresAt a string' => [['expiresAt' => 'x']];
        yield 'expiresAt missing' => [['expiresAt' => null]];
        yield 'expiresAt a float' => [['expiresAt' => 1.5]];
        yield 'absoluteExpiresAt a string' => [['absoluteExpiresAt' => 'x']];
        yield 'absoluteExpiresAt missing' => [['absoluteExpiresAt' => null]];
        yield 'idle past absolute' => [['expiresAt' => 2_000_000_000, 'absoluteExpiresAt' => 1_900_000_000]];
        yield 'created beyond the skew allowance' => [['createdAt' => 1_800_000_000 + 301, 'renewedAt' => 1_800_000_000 + 301, 'absoluteExpiresAt' => 1_800_000_301 + 604_800, 'expiresAt' => 1_800_000_301 + 86_400]];
        yield 'absolute equal to created' => [['absoluteExpiresAt' => 1_790_000_000, 'createdAt' => 1_790_000_000, 'renewedAt' => 1_790_000_000, 'expiresAt' => 1_790_000_000]];
        yield 'renewed before created' => [['renewedAt' => 1, 'createdAt' => 1_790_000_000]];
    }

    /**
     * @param array<string, mixed> $override a null value removes the attribute
     */
    #[DataProvider('corruptFields')]
    public function testAnItemWithAWrongOrMissingFieldIsACorruptItemFailureNeverTrusted(array $override): void
    {
        $hash = hash('sha256', 'invented-corrupt');
        $this->put($hash, $override);

        try {
            $this->repository()->find($hash, $this->now());
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('CorruptItem', $failure->awsErrorCode);
        }
    }

    public function testRenewMovesTheIdleExpiryAndRenewedAt(): void
    {
        $repository = $this->repository();
        $token = $repository->create(self::ID);
        $session = $repository->find($token->hash(), $this->now());
        self::assertNotNull($session);
        $this->clock->advance(3600);

        $renewed = $repository->renew($session, $this->now());

        self::assertSame($this->now(), $renewed->renewedAt);
        self::assertSame($this->now() + 86_400, $renewed->expiresAt);
        self::assertSame($session->createdAt, $renewed->createdAt);
        self::assertSame($session->absoluteExpiresAt, $renewed->absoluteExpiresAt);
        $stored = $repository->find($token->hash(), $this->now());
        self::assertEquals($renewed, $stored);
    }

    public function testRenewNeverGoesPastTheAbsoluteCapAndKeepsNumbers(): void
    {
        $hash = hash('sha256', 'invented-cap');
        $this->put($hash, ['absoluteExpiresAt' => $this->now() + 1000, 'expiresAt' => $this->now() + 500]);
        $session = $this->repository()->find($hash, $this->now());
        self::assertNotNull($session);

        $renewed = $this->repository()->renew($session, $this->now());

        self::assertSame($this->now() + 1000, $renewed->expiresAt);
        self::assertSame(['N'], array_keys($this->rawItem($hash)['expiresAt']));
    }

    public function testRenewOfADeletedSessionIsConditionFailedAndCreatesNothing(): void
    {
        $repository = $this->repository();
        $token = $repository->create(self::ID);
        $session = $repository->find($token->hash(), $this->now());
        self::assertNotNull($session);
        $repository->delete($token->hash());

        try {
            $repository->renew($session, $this->now() + 900);
            self::fail('expected ConditionFailed');
        } catch (ConditionFailed) {
        }

        self::assertSame([], $this->allItems());
    }

    public function testRenewOfAnItemWhoseIdleExpiryHasPassedIsConditionFailed(): void
    {
        $repository = $this->repository();
        $token = $repository->create(self::ID);
        $session = $repository->find($token->hash(), $this->now());
        self::assertNotNull($session);

        $this->expectException(ConditionFailed::class);

        $repository->renew($session, $this->now() + 86_400);
    }

    public function testDeleteRemovesTheSessionAndIsIdempotent(): void
    {
        $repository = $this->repository();
        $token = $repository->create(self::ID);

        $repository->delete($token->hash());
        $repository->delete($token->hash());

        self::assertNull($repository->find($token->hash(), $this->now()));
    }

    public function testReplayingCreateWithTheSameItemIsASuccess(): void
    {
        $token = SessionToken::generate();
        $repository = $this->repository([$token, $token]);

        $first = $repository->create(self::ID);
        $second = $repository->create(self::ID);

        self::assertSame($first->hash(), $second->hash());
        self::assertCount(1, $this->allItems());
        self::assertNotNull($repository->find($token->hash(), $this->now()));
    }

    public function testARealCollisionTriesOneNewTokenAndLeavesTheOtherUsersSessionAlone(): void
    {
        $taken = SessionToken::generate();
        $fresh = SessionToken::generate();
        $this->repository([$taken])->create(self::OTHER_ID);

        $created = $this->repository([$taken, $fresh])->create(self::ID);

        self::assertSame($fresh->hash(), $created->hash());
        self::assertSame(self::OTHER_ID, $this->repository()->find($taken->hash(), $this->now())?->userId);
        self::assertSame(self::ID, $this->repository()->find($fresh->hash(), $this->now())?->userId);
    }

    public function testAnotherCreatedAtWithTheSameUserIsNotMistakenForAReplay(): void
    {
        $taken = SessionToken::generate();
        $fresh = SessionToken::generate();
        $this->repository([$taken])->create(self::ID);
        $this->clock->advance(5);

        $created = $this->repository([$taken, $fresh])->create(self::ID);

        self::assertSame($fresh->hash(), $created->hash());
        self::assertCount(2, $this->allItems());
    }

    public function testTwoCollisionsInARowFailAsAStorageFailure(): void
    {
        $taken = SessionToken::generate();
        $this->repository([$taken])->create(self::OTHER_ID);

        try {
            $this->repository([$taken, $taken])->create(self::ID);
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('SessionCollision', $failure->awsErrorCode);
            self::assertStringNotContainsString($taken->cookieValue(), $failure->getMessage());
        }

        self::assertCount(1, $this->allItems());
    }

    /**
     * Writes a valid session item for the hash, with fields replaced (or removed, for null) by $override.
     *
     * @param array<string, mixed> $override
     */
    private function put(string $hash, array $override): void
    {
        $item = AuthKeys::session($hash) + [
            'userId' => self::ID,
            'createdAt' => $this->now(),
            'renewedAt' => $this->now(),
            'expiresAt' => $this->now() + 86_400,
            'absoluteExpiresAt' => $this->now() + 604_800,
        ];
        $item = $override + $item;
        $this->store->putItem(array_filter($item, static fn(mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawItem(string $hash): array
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $result = $client->getItem([
            'TableName' => $this->table->name,
            'Key' => ['PK' => ['S' => 'SESSION#' . $hash], 'SK' => ['S' => 'SESSION']],
            'ConsistentRead' => true,
        ]);
        $item = $result['Item'];
        self::assertIsArray($item);
        /** @var array<string, array<string, mixed>> $item */

        return $item;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allItems(): array
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $result = $client->scan(['TableName' => $this->table->name, 'ConsistentRead' => true]);
        $items = $result['Items'];
        self::assertIsArray($items);
        /** @var list<array<string, mixed>> $items */

        return $items;
    }
}
