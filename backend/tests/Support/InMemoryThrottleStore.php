<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Throttle\ThrottleDecision;
use App\Auth\Throttle\ThrottleHit;
use App\Auth\Throttle\ThrottleRule;
use App\Auth\Throttle\ThrottleStore;
use Throwable;

/**
 * A throttle store in memory that records its calls. A bucket is blocked once its count passes the rule's limit; the
 * Retry-After it reports is scripted per scope (default 60), so tests can check which bucket decided.
 */
final class InMemoryThrottleStore implements ThrottleStore
{
    private const DEFAULT_RETRY_AFTER = 60;

    /** The start of every window; a hit reports this plus the rule's window as the end of its window. */
    public const WINDOW_END = 1_800_000_000;

    /** @var array<string, int> count by "SCOPE|key" */
    public array $counts = [];

    /** @var list<string> "hit|SCOPE|key", "refund|SCOPE|key" or "delete|SCOPE|key", in call order */
    public array $calls = [];

    /** @var array<string, int> */
    public array $retryAfterByScope = [];

    /** @var array<string, int> window end given to refund(), by "SCOPE|key" */
    public array $refundedWindows = [];

    /** @var array<string, Throwable> thrown (after the call is recorded) by "operation|SCOPE", e.g. "delete|LOGIN-EMAIL" */
    public array $failures = [];

    public function seed(string $scope, string $key, int $count): void
    {
        $this->counts[$scope . '|' . $key] = $count;
    }

    public function count(string $scope, string $key): int
    {
        return $this->counts[$scope . '|' . $key] ?? 0;
    }

    public function hit(ThrottleRule $rule, string $key): ThrottleHit
    {
        $id = $rule->scope . '|' . $key;
        $this->calls[] = 'hit|' . $id;
        $this->counts[$id] = ($this->counts[$id] ?? 0) + 1;

        $decision = $this->counts[$id] > $rule->limit
            ? ThrottleDecision::blocked($this->retryAfterByScope[$rule->scope] ?? self::DEFAULT_RETRY_AFTER)
            : ThrottleDecision::allowed();

        return new ThrottleHit($decision, self::WINDOW_END + $rule->windowSeconds);
    }

    public function refund(ThrottleRule $rule, string $key, int $countedWindowExpiresAt): void
    {
        $id = $rule->scope . '|' . $key;
        $this->calls[] = 'refund|' . $id;
        $this->refundedWindows[$id] = $countedWindowExpiresAt;
        $this->failIfScripted('refund', $rule);
        $this->counts[$id] = max(0, ($this->counts[$id] ?? 0) - 1);
    }

    public function delete(ThrottleRule $rule, string $key): void
    {
        $id = $rule->scope . '|' . $key;
        $this->calls[] = 'delete|' . $id;
        $this->failIfScripted('delete', $rule);
        unset($this->counts[$id]);
    }

    private function failIfScripted(string $operation, ThrottleRule $rule): void
    {
        $failure = $this->failures[$operation . '|' . $rule->scope] ?? null;
        if ($failure !== null) {
            throw $failure;
        }
    }
}
