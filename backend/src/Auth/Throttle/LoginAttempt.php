<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

use LogicException;

/**
 * Proof that one login attempt was counted and allowed, and where: the buckets it was counted in, each with the end of
 * the window it was counted in. Opaque to callers; the only thing to do with it is
 * {@see LoginThrottle::succeeded()}, which accepts it once. Only LoginThrottle can create one (the constructor is
 * private and the factory needs the LoginThrottle itself), so a refund can only follow a counted, allowed attempt. It cannot be cloned, serialised or unserialised, because a
 * copy would allow a double refund, and `var_dump` and `print_r` show nothing (the buckets hold the account key).
 */
final class LoginAttempt
{
    private bool $used = false;

    /**
     * @param list<array{ThrottleRule, string, int}> $buckets rule, key and counted window end of each bucket
     */
    private function __construct(private readonly array $buckets) {}

    /**
     * @internal for LoginThrottle only
     *
     * @param list<array{ThrottleRule, string, int}> $buckets
     */
    public static function issuedBy(LoginThrottle $issuer, array $buckets): self
    {
        return new self($buckets);
    }

    /**
     * @internal for LoginThrottle only
     *
     * @return list<array{ThrottleRule, string, int}>
     *
     * @throws LogicException when the attempt was already used
     */
    public function consume(LoginThrottle $consumer): array
    {
        if ($this->used) {
            throw new LogicException('A login attempt can be marked as succeeded only once.');
        }
        $this->used = true;

        return $this->buckets;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A login attempt must not be serialised.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A login attempt must not be unserialised.');
    }

    public function __clone()
    {
        throw new LogicException('A login attempt must not be cloned.');
    }
}
