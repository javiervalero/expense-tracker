<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

use App\Http\ClientAddress;
use App\Infrastructure\DynamoDb\StorageFailure;

/**
 * The registration throttle (A13, A14): per address (IPv4, IPv6 `/64`, or the shared `unknown`) and, for IPv6, per
 * `/48`. Every attempt counts, successful or not.
 */
final readonly class RegisterThrottle
{
    public function __construct(private ThrottleStore $store) {}

    /**
     * @throws StorageFailure
     */
    public function attempt(ClientAddress $address): ThrottleDecision
    {
        $decisions = [$this->store->hit(ThrottleRule::registerAddress(), $address->throttleKey)->decision];
        $network = NetworkPrefix::of($address);
        if ($network !== null) {
            $decisions[] = $this->store->hit(ThrottleRule::registerNetwork(), $network)->decision;
        }

        return ThrottleDecision::strictest(...$decisions);
    }
}
