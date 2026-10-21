<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

use App\Infrastructure\DynamoDb\StorageFailure;
use InvalidArgumentException;

/**
 * The counters behind the login and registration throttles, so those can be tested with a fake.
 */
interface ThrottleStore
{
    /**
     * Counts one attempt in the bucket, decides on the new count and reports the end of the window it was counted in.
     *
     * @throws InvalidArgumentException when the key does not fit the rule's scope
     * @throws StorageFailure
     */
    public function hit(ThrottleRule $rule, string $key): ThrottleHit;

    /**
     * Takes one attempt back, never below zero, and only from the window the attempt was counted in (the
     * `windowExpiresAt` that hit() reported); a missing bucket or one in another window stays as it is.
     *
     * @throws InvalidArgumentException
     * @throws StorageFailure
     */
    public function refund(ThrottleRule $rule, string $key, int $countedWindowExpiresAt): void;

    /**
     * @throws InvalidArgumentException
     * @throws StorageFailure
     */
    public function delete(ThrottleRule $rule, string $key): void;
}
