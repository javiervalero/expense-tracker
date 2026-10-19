<?php

declare(strict_types=1);

namespace App\Auth\Session;

use App\Auth\AuthKeys;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\Id\Uuid;
use Closure;
use InvalidArgumentException;

/**
 * Sessions in the single table: `SESSION#<token hash>/SESSION`, with every time as an epoch-second Number (the TTL
 * attribute `expiresAt` must be one). Whatever is read back is checked field by field; an item with a missing field,
 * a field of the wrong type, an impossible order of times or a creation time in the future is a {@see StorageFailure} (`CorruptItem`), never
 * trusted. An absolute expiry beyond the current {@see SessionLifetime::ABSOLUTE} is capped on read, not refused. Expiry is checked on every read: the TTL only cleans up, and late.
 */
final readonly class SessionRepository implements SessionStore
{
    private const COLLISION = 'SessionCollision';
    private const SESSION_SORT_KEY = 'SESSION';
    private const MAX_ATTEMPTS = 2;
    /** How far ahead of the clock a creation time may be before the item is not believed. */
    private const CLOCK_SKEW_SECONDS = 300;

    /** @var Closure(): SessionToken */
    private Closure $generateToken;

    /**
     * @param (Closure(): SessionToken)|null $generateToken for tests only, to script collisions; omitted, it is
     *                                                       {@see SessionToken::generate()}. An architecture test
     *                                                       (SessionTokenGeneratorTest) fails if src/ passes one.
     */
    public function __construct(
        private ItemStore $items,
        private Clock $clock,
        ?Closure $generateToken = null,
    ) {
        $this->generateToken = $generateToken ?? static fn(): SessionToken => SessionToken::generate();
    }

    /**
     * The token is the only thing that leaves: never log it.
     *
     * A19: the SDK may retry a write whose first attempt went through, so a failed condition is re-read first. An item
     * that holds our user id and creation time is our own earlier attempt (success). Anything else is a real
     * collision of two random 256-bit tokens, which is not going to happen: one new token is tried, then it fails.
     *
     * @throws InvalidArgumentException when the user id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function create(string $userId): SessionToken
    {
        if (!Uuid::isValid($userId)) {
            throw new InvalidArgumentException('A user id is a lower-case UUID.');
        }
        $now = $this->clock->now()->getTimestamp();
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $token = ($this->generateToken)();
            $session = Session::start($userId, $token->hash(), $now);
            try {
                $this->items->putItem(self::toItem($session), 'attribute_not_exists(PK)');

                return $token;
            } catch (ConditionFailed) {
                if ($this->isOurs($session)) {
                    return $token;
                }
            }
        }

        throw new StorageFailure('PutItem', self::COLLISION);
    }

    /**
     * @throws StorageFailure
     */
    public function find(#[\SensitiveParameter] string $tokenHash, int $now): ?Session
    {
        $item = $this->items->getItem(AuthKeys::session($tokenHash));
        if ($item === null) {
            return null;
        }
        $session = self::hydrate($tokenHash, $item, 'GetItem', $now);

        return $session->isActiveAt($now) ? $session : null;
    }

    /**
     * @throws ConditionFailed when the session is gone or its idle expiry has passed
     * @throws StorageFailure
     */
    public function renew(Session $session, int $now): Session
    {
        $renewed = $session->renewed($now);
        $stored = $this->items->updateItem(
            AuthKeys::session($session->tokenHash),
            'SET #renewedAt = :renewedAt, #expiresAt = :expiresAt',
            ['#renewedAt' => 'renewedAt', '#expiresAt' => TableLayout::EXPIRES_AT],
            [':renewedAt' => $renewed->renewedAt, ':expiresAt' => $renewed->expiresAt, ':now' => $now],
            'attribute_exists(PK) AND #expiresAt > :now',
        );

        return self::hydrate($session->tokenHash, $stored, 'UpdateItem', $now);
    }

    /**
     * @throws StorageFailure
     */
    public function delete(#[\SensitiveParameter] string $tokenHash): void
    {
        $this->items->deleteItem(AuthKeys::session($tokenHash));
    }

    private function isOurs(Session $session): bool
    {
        $item = $this->items->getItem(AuthKeys::session($session->tokenHash));
        if ($item === null) {
            return false;
        }
        $stored = self::hydrate($session->tokenHash, $item, 'GetItem', $session->createdAt);

        return $stored->userId === $session->userId && $stored->createdAt === $session->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toItem(Session $session): array
    {
        return AuthKeys::session($session->tokenHash) + [
            'userId' => $session->userId,
            'createdAt' => $session->createdAt,
            'renewedAt' => $session->renewedAt,
            'absoluteExpiresAt' => $session->absoluteExpiresAt,
            TableLayout::EXPIRES_AT => $session->expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @throws StorageFailure
     */
    private static function hydrate(#[\SensitiveParameter] string $tokenHash, array $item, string $operation, int $now): Session
    {
        $userId = $item['userId'] ?? null;
        $times = [];
        foreach (['createdAt', 'renewedAt', 'absoluteExpiresAt', TableLayout::EXPIRES_AT] as $field) {
            $value = $item[$field] ?? null;
            if (!is_int($value) || $value < 1) {
                throw new StorageFailure($operation, SessionStore::CORRUPT_ITEM);
            }
            $times[$field] = $value;
        }
        if (
            ($item[TableLayout::PK] ?? null) !== AuthKeys::session($tokenHash)[TableLayout::PK]
            || ($item[TableLayout::SK] ?? null) !== self::SESSION_SORT_KEY
            || !is_string($userId)
            || !Uuid::isValid($userId)
            || $times['renewedAt'] < $times['createdAt']
            || $times['absoluteExpiresAt'] <= $times['createdAt']
            || $times['createdAt'] > $now + self::CLOCK_SKEW_SECONDS
            || $times[TableLayout::EXPIRES_AT] > $times['absoluteExpiresAt']
        ) {
            throw new StorageFailure($operation, SessionStore::CORRUPT_ITEM);
        }

        // If ABSOLUTE was lowered since the item was written, the session just ends sooner.
        $absolute = min($times['absoluteExpiresAt'], $times['createdAt'] + SessionLifetime::ABSOLUTE);

        return new Session(
            $userId,
            $tokenHash,
            $times['createdAt'],
            $times['renewedAt'],
            $absolute,
            min($times[TableLayout::EXPIRES_AT], $absolute),
        );
    }
}
