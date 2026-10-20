<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

use App\Auth\AuthKeys;
use App\Http\ClientAddress;

/**
 * The coarse throttle tier (A14): the IPv6 `/48` of the canonical address, written like ClientIp writes its `/64`
 * (`2001:db8:1::/48`). IPv4, IPv4-mapped IPv6 (ClientIp already made it IPv4) and an unknown address have none.
 */
final class NetworkPrefix
{
    private const IPV6_BYTES = 16;

    public static function of(ClientAddress $address): ?string
    {
        $ip = $address->ip;
        if ($ip === null || !str_contains($ip, ':')) {
            return null;
        }
        $binary = @inet_pton($ip);
        if ($binary === false || strlen($binary) !== self::IPV6_BYTES) {
            return null;
        }
        $prefixBytes = intdiv(AuthKeys::IPV6_NETWORK_PREFIX_BITS, 8);
        $prefix = substr($binary, 0, $prefixBytes) . str_repeat("\0", self::IPV6_BYTES - $prefixBytes);

        return inet_ntop($prefix) . '/' . AuthKeys::IPV6_NETWORK_PREFIX_BITS;
    }
}
