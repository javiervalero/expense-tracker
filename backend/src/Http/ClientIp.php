<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Derives the caller's address from ONE header value, `host:port` as CloudFront sets it (`CloudFront-Viewer-Address`).
 * A pure parser: it never reads `REMOTE_ADDR`, `X-Forwarded-For`, `Forwarded` or `X-Real-IP`, and it never repairs or
 * trims. Anything that is not exactly one well-formed `host:port` is {@see ClientAddress::unknown()} with a reason
 * (`missing`, `empty`, `too-long`, `multiple-values`, `invalid`); the caller logs it and carries on (fail closed).
 *
 *  - IPv4 `a.b.c.d:port`, bracketed IPv6 `[addr]:port`, and unbracketed IPv6 `addr:port` (CloudFront's form: split at
 *    the last colon). Brackets are only allowed around IPv6. Private and loopback addresses are valid (local use).
 *  - The port is decimal, 1-65535, with no sign and no leading zero.
 *  - Only `[0-9A-Fa-f.:[]]` is accepted, so whitespace, zone ids (`%`) and control characters are refused; a value over
 *    64 characters is refused (the longest legitimate one has 53). A comma means several values (nginx and API Gateway
 *    join a repeated header with ", ").
 *  - The result is canonical (lower case). IPv4-mapped IPv6 (`::ffff:a.b.c.d`) becomes the IPv4 address, otherwise all
 *    IPv4 clients would share one `::/64` bucket. The throttle key of an IPv6 address is its `/64` prefix.
 *
 * Known and accepted ambiguity: an unbracketed IPv6 address WITHOUT a port whose last group is 1-5 decimal digits
 * (`2001:db8::1:2`) is read as host `2001:db8::1` and port `2`. The `/64` key is the same either way, so counting is
 * unaffected.
 */
final class ClientIp
{
    private const MAX_LENGTH = 64;
    private const MAX_PORT = 65535;
    private const ALLOWED_CHARACTERS = '/\A[0-9A-Fa-f.:\[\]]+\z/';
    private const BRACKETED = '/\A\[([0-9A-Fa-f.:]+)\]:([0-9]+)\z/';
    private const PORT = '/\A[1-9][0-9]{0,4}\z/';
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";
    private const IPV6_PREFIX_BYTES = 8;
    private const IPV6_BYTES = 16;

    public static function parse(?string $value): ClientAddress
    {
        $hostAndPort = self::split($value);
        if (is_string($hostAndPort)) {
            return ClientAddress::unknown($hostAndPort);
        }
        [$host, $port] = $hostAndPort;
        if (preg_match(self::PORT, $port) !== 1 || (int) $port > self::MAX_PORT) {
            return ClientAddress::unknown('invalid');
        }
        $binary = str_contains($host, ':') ? self::ipv6($host) : self::ipv4($host);

        return $binary === null ? ClientAddress::unknown('invalid') : self::address($binary);
    }

    /**
     * @return array{string, string}|string host and port, or the reason why there are none
     */
    private static function split(?string $value): array|string
    {
        if ($value === null) {
            return 'missing';
        }
        if ($value === '') {
            return 'empty';
        }
        if (strlen($value) > self::MAX_LENGTH) {
            return 'too-long';
        }
        if (str_contains($value, ',')) {
            return 'multiple-values';
        }
        if (preg_match(self::ALLOWED_CHARACTERS, $value) !== 1) {
            return 'invalid';
        }
        if (str_starts_with($value, '[')) {
            return preg_match(self::BRACKETED, $value, $match) === 1 && str_contains($match[1], ':')
                ? [$match[1], $match[2]]
                : 'invalid';
        }
        $colon = strrpos($value, ':');
        if ($colon === false || str_contains($value, '[') || str_contains($value, ']')) {
            return 'invalid';
        }

        return [substr($value, 0, $colon), substr($value, $colon + 1)];
    }

    /**
     * @return string|null the 4 bytes, null if not a plain dotted-quad IPv4 address
     */
    private static function ipv4(string $host): ?string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : self::pack($host);
    }

    /**
     * @return string|null the 16 bytes, null if not an IPv6 address
     */
    private static function ipv6(string $host): ?string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? null : self::pack($host);
    }

    private static function pack(string $text): ?string
    {
        $binary = inet_pton($text);

        return $binary === false ? null : $binary;
    }

    private static function address(string $binary): ClientAddress
    {
        if (strlen($binary) === self::IPV6_BYTES && str_starts_with($binary, self::IPV4_MAPPED_PREFIX)) {
            $binary = substr($binary, strlen(self::IPV4_MAPPED_PREFIX));
        }
        $ip = (string) inet_ntop($binary);
        if (strlen($binary) !== self::IPV6_BYTES) {
            return ClientAddress::of($ip, $ip);
        }
        $prefix = substr($binary, 0, self::IPV6_PREFIX_BYTES) . str_repeat("\0", self::IPV6_PREFIX_BYTES);

        return ClientAddress::of($ip, inet_ntop($prefix) . '/64');
    }
}
