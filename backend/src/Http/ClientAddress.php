<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The caller's address as {@see ClientIp} derived it: the canonical `ip` (null when unknown), the key to count
 * requests by (`throttleKey`: the IPv4 address, the IPv6 `/64` prefix, or `unknown`), and why there is no address.
 * An unknown address is a real, shared bucket (`unknown`), never an exemption.
 */
final readonly class ClientAddress
{
    public const UNKNOWN_KEY = 'unknown';

    private function __construct(
        public ?string $ip,
        public string $throttleKey,
        public ?string $reason,
    ) {}

    public static function of(string $ip, string $throttleKey): self
    {
        return new self($ip, $throttleKey, null);
    }

    public static function unknown(string $reason): self
    {
        return new self(null, self::UNKNOWN_KEY, $reason);
    }
}
