<?php

declare(strict_types=1);

namespace App\Auth\Session;

/**
 * A stored session as immutable data. All times are epoch seconds. `expiresAt` is the idle expiry and never goes past
 * `absoluteExpiresAt`. The token itself is never held, only its hash.
 */
final readonly class Session
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter]
        public string $tokenHash,
        public int $createdAt,
        public int $renewedAt,
        public int $absoluteExpiresAt,
        public int $expiresAt,
    ) {}

    public static function start(string $userId, #[\SensitiveParameter] string $tokenHash, int $now): self
    {
        $absolute = $now + SessionLifetime::ABSOLUTE;

        return new self($userId, $tokenHash, $now, $now, $absolute, min($now + SessionLifetime::IDLE, $absolute));
    }

    /** Valid only while both the idle and the absolute expiry are still ahead (TTL deletes late, A3). */
    public function isActiveAt(int $now): bool
    {
        return $this->expiresAt > $now && $this->absoluteExpiresAt > $now;
    }

    /** True when the last renewal is at least RENEW_AFTER old and a renewal would move the expiry forward. */
    public function dueForRenewal(int $now): bool
    {
        return $now - $this->renewedAt >= SessionLifetime::RENEW_AFTER && $this->renewedExpiry($now) > $this->expiresAt;
    }

    public function renewed(int $now): self
    {
        return new self($this->userId, $this->tokenHash, $this->createdAt, $now, $this->absoluteExpiresAt, $this->renewedExpiry($now));
    }

    private function renewedExpiry(int $now): int
    {
        return min($now + SessionLifetime::IDLE, $this->absoluteExpiresAt);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'tokenHash' => '[redacted]'];
    }
}
