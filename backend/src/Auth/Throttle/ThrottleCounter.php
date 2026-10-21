<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

use App\Auth\AuthKeys;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TableLayout;

/**
 * Fixed-window counters in the single table (A15): `THROTTLE#<scope>#<key>/THROTTLE` with `attempts` and `expiresAt`
 * (both Numbers; `expiresAt` is the TTL attribute and the end of the window).
 *
 * Increment first: one atomic `ADD attempts 1`, guarded so that it only applies to a bucket that is missing or still
 * inside its window, and the returned count decides. N parallel attempts get N distinct counts, so only `limit` pass.
 * A bucket whose window has ended but which TTL has not deleted yet (TTL is late, up to days) is reset by a
 * conditional put; if another request reset it first, the put fails and the increment is tried once more.
 * Expiry is always checked in the conditions against `expiresAt`, never left to TTL. Whatever is read back is checked
 * field by field and is a {@see StorageFailure} (`CorruptItem`) if it does not fit: that fails closed (a 500), and
 * Phase 7 alarms on `CorruptItem`.
 */
final readonly class ThrottleCounter implements ThrottleStore
{
    private const RACE = 'ThrottleRace';
    /** How far past the end of the longest possible window a stored `expiresAt` may be before it is not believed. */
    private const CLOCK_SKEW_SECONDS = 300;

    public function __construct(private ItemStore $items, private Clock $clock) {}

    /**
     * @throws StorageFailure
     */
    public function hit(ThrottleRule $rule, string $key): ThrottleHit
    {
        $keys = AuthKeys::throttle($rule->scope, $key);
        $now = $this->clock->now()->getTimestamp();
        $expiresAt = $now + $rule->windowSeconds;

        $item = $this->increment($keys, $now, $expiresAt) ?? $this->resetOrRetry($keys, $now, $expiresAt);
        $stored = self::validated($item, $keys, 'UpdateItem', $expiresAt);

        return new ThrottleHit(
            $stored['attempts'] > $rule->limit
                ? ThrottleDecision::blocked($stored['expiresAt'] - $now)
                : ThrottleDecision::allowed(),
            $stored['expiresAt'],
        );
    }

    /**
     * Only the window the attempt was counted in is touched: after a rollover the counted window's `expiresAt` no
     * longer matches, so the refund cannot land in the new window. The `> 0` bound stops a refund the SDK retried
     * (after a lost response) at zero; within one window such a double decrement is still possible, but it can give
     * back at most one extra attempt.
     *
     * @throws StorageFailure
     */
    public function refund(ThrottleRule $rule, string $key, int $countedWindowExpiresAt): void
    {
        $keys = AuthKeys::throttle($rule->scope, $key);
        try {
            $this->items->updateItem(
                $keys,
                'ADD #attempts :minusOne',
                ['#attempts' => 'attempts', '#expiresAt' => TableLayout::EXPIRES_AT],
                [':minusOne' => -1, ':countedWindow' => $countedWindowExpiresAt, ':zero' => 0],
                'attribute_exists(PK) AND #expiresAt = :countedWindow AND #attempts > :zero',
            );
        } catch (ConditionFailed) {
            // No bucket, one in another window than the attempt was counted in, or one already at zero: nothing to give back.
        }
    }

    /**
     * @throws StorageFailure
     */
    public function delete(ThrottleRule $rule, string $key): void
    {
        $this->items->deleteItem(AuthKeys::throttle($rule->scope, $key));
    }

    /**
     * The window had ended: reset it. If another request got there first, count into its new window, once.
     *
     * @param array{PK: string, SK: string} $keys
     *
     * @return array<string, mixed>
     *
     * @throws StorageFailure
     */
    private function resetOrRetry(array $keys, int $now, int $expiresAt): array
    {
        $item = $this->reset($keys, $now, $expiresAt);
        if ($item !== null) {
            return $item;
        }
        $item = $this->increment($keys, $now, $expiresAt);
        if ($item === null) {
            throw new StorageFailure('UpdateItem', self::RACE);
        }

        return $item;
    }

    /**
     * @param array{PK: string, SK: string} $keys
     *
     * @return array<string, mixed>|null the item after the update, or null when the bucket exists but its window has ended
     *
     * @throws StorageFailure
     */
    private function increment(array $keys, int $now, int $expiresAt): ?array
    {
        try {
            return $this->items->updateItem(
                $keys,
                'SET #expiresAt = if_not_exists(#expiresAt, :expires) ADD #attempts :one',
                ['#attempts' => 'attempts', '#expiresAt' => TableLayout::EXPIRES_AT],
                [':one' => 1, ':now' => $now, ':expires' => $expiresAt],
                'attribute_not_exists(PK) OR #expiresAt > :now',
            );
        } catch (ConditionFailed) {
            return null;
        }
    }

    /**
     * The condition is the exact complement of the increment guard (`attribute_not_exists(PK) OR #expiresAt > :now`),
     * so every item the increment refused is reset here, including one whose `expiresAt` is missing or not a Number
     * (a comparison with a missing or differently typed attribute is false, so its NOT is true). Nothing can fall
     * between the two as a ThrottleRace.
     *
     * @param array{PK: string, SK: string} $keys
     *
     * @return array<string, mixed>|null the new first-attempt item, or null when another request reset the bucket first
     *
     * @throws StorageFailure
     */
    private function reset(array $keys, int $now, int $expiresAt): ?array
    {
        $item = $keys + ['attempts' => 1, TableLayout::EXPIRES_AT => $expiresAt];
        try {
            $this->items->putItem(
                $item,
                'attribute_not_exists(PK) OR NOT (#expiresAt > :now)',
                ['#expiresAt' => TableLayout::EXPIRES_AT],
                [':now' => $now],
            );
        } catch (ConditionFailed) {
            return null;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @param array{PK: string, SK: string} $keys
     *
     * @return array{attempts: int, expiresAt: int}
     *
     * @throws StorageFailure
     */
    private static function validated(array $item, array $keys, string $operation, int $windowEnd): array
    {
        $attempts = $item['attempts'] ?? null;
        $expiresAt = $item[TableLayout::EXPIRES_AT] ?? null;
        if (
            ($item[TableLayout::PK] ?? null) !== $keys[TableLayout::PK]
            || ($item[TableLayout::SK] ?? null) !== AuthKeys::THROTTLE_SORT_KEY
            || !is_int($attempts)
            || $attempts < 1
            || !is_int($expiresAt)
            || $expiresAt < 1
            || $expiresAt > $windowEnd + self::CLOCK_SKEW_SECONDS
        ) {
            throw new StorageFailure($operation, StorageFailure::CORRUPT_ITEM);
        }

        return ['attempts' => $attempts, 'expiresAt' => $expiresAt];
    }
}
