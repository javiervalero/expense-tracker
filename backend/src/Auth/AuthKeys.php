<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Session\Principal;
use App\Http\ClientAddress;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\Id\Uuid;
use InvalidArgumentException;

/**
 * The primary keys of the authentication items (single table, ADR 0002). A key is never parsed back, so a `#` inside an
 * e-mail address is harmless. User ids come from a session principal or a freshly generated id, never from the request.
 */
final class AuthKeys
{
    public const string LOGIN_EMAIL = 'LOGIN-EMAIL';
    public const string LOGIN_IP = 'LOGIN-IP';
    public const string LOGIN_NET = 'LOGIN-NET';
    public const string REGISTER_IP = 'REGISTER-IP';
    public const string REGISTER_NET = 'REGISTER-NET';

    /** The coarse IPv6 throttle tier, the one place the `/48` is defined: NetworkPrefix derives its byte count and suffix from it. */
    public const int IPV6_NETWORK_PREFIX_BITS = 48;

    /** The IPv6 prefix of the per-address throttle key (`/64`); ClientIp writes it, and ClientIpTest pins the two together. */
    public const int IPV6_ADDRESS_PREFIX_BITS = 64;

    /** The sort key of a session item (shared with SessionRepository, which checks it on read). Not `SESSION_SORT_KEY`: NoNativeSessionsTest rejects any name starting `session_`. */
    public const string AUTH_SESSION_SORT_KEY = 'SESSION';

    /** The sort key of every throttle bucket (shared with ThrottleCounter, which checks it on read). */
    public const string THROTTLE_SORT_KEY = 'THROTTLE';

    /** The five throttle scopes (A15). */
    public const array THROTTLE_SCOPES = [self::LOGIN_EMAIL, self::LOGIN_IP, self::LOGIN_NET, self::REGISTER_IP, self::REGISTER_NET];

    private const SHA256_HEX = '/\A[0-9a-f]{64}\z/D';

    /**
     * The partition key of a user's items (`USER#<id>`): the one place the prefix is spelled, which
     * UserPartitionQueryTest enforces. Features build theirs with {@see self::userPartitionOf()}, from the session principal.
     *
     * @throws InvalidArgumentException when the id is not a lower-case canonical UUID
     */
    public static function userPartition(string $userId): string
    {
        return 'USER#' . Uuid::require($userId, 'user id');
    }

    /**
     * The partition key of the signed-in user's items. This is the form every feature uses: the user id comes from the
     * session, never from the request. UserPartitionCallTest keeps {@see self::userPartition()} to this file.
     *
     * @throws InvalidArgumentException when the principal's id is not a lower-case canonical UUID
     */
    public static function userPartitionOf(Principal $owner): string
    {
        return self::userPartition($owner->userId);
    }

    /**
     * @return array{PK: string, SK: string}
     *
     * @throws InvalidArgumentException when the id is not a lower-case canonical UUID
     */
    public static function user(string $userId): array
    {
        return [TableLayout::PK => self::userPartition($userId), TableLayout::SK => 'PROFILE'];
    }

    /**
     * @return array{PK: string, SK: string}
     */
    public static function emailLock(Email $email): array
    {
        return [TableLayout::PK => 'EMAIL#' . $email->value(), TableLayout::SK => 'EMAIL'];
    }

    /**
     * @return array{PK: string, SK: string}
     *
     * @throws InvalidArgumentException when the hash is not 64 lower-case hex characters (a SHA-256 of a token)
     */
    public static function session(#[\SensitiveParameter] string $tokenHash): array
    {
        if (preg_match(self::SHA256_HEX, $tokenHash) !== 1) {
            throw new InvalidArgumentException('A session token hash is 64 lower-case hex characters.');
        }

        return [TableLayout::PK => 'SESSION#' . $tokenHash, TableLayout::SK => self::AUTH_SESSION_SORT_KEY];
    }

    /**
     * A throttle bucket (A15): `THROTTLE#<scope>#<key>`. The key is checked against what its scope produces, so a raw
     * e-mail or a free-form string can never become a key: `LOGIN-EMAIL` takes the SHA-256 hex of the normalised
     * e-mail; `*-IP` takes `unknown`, an IPv4 address or an IPv6 `/64` prefix (`ClientAddress::$throttleKey`); `*-NET`
     * takes an IPv6 `/48` prefix.
     *
     * @return array{PK: string, SK: string}
     *
     * @throws InvalidArgumentException for an unknown scope or a key of the wrong shape for its scope
     */
    public static function throttle(string $scope, string $key): array
    {
        $valid = match ($scope) {
            self::LOGIN_EMAIL => preg_match(self::SHA256_HEX, $key) === 1,
            self::LOGIN_IP, self::REGISTER_IP => $key === ClientAddress::UNKNOWN_KEY
                || filter_var($key, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                || self::isIpv6Prefix($key, self::IPV6_ADDRESS_PREFIX_BITS),
            self::LOGIN_NET, self::REGISTER_NET => self::isIpv6Prefix($key, self::IPV6_NETWORK_PREFIX_BITS),
            default => throw new InvalidArgumentException('Unknown throttle scope.'),
        };
        if (!$valid) {
            throw new InvalidArgumentException('The throttle key does not fit its scope.');
        }

        return [TableLayout::PK => 'THROTTLE#' . $scope . '#' . $key, TableLayout::SK => self::THROTTLE_SORT_KEY];
    }

    /**
     * Canonical `<ipv6>/<bits>` with every bit after the prefix zero, exactly as ClientIp and NetworkPrefix write it.
     */
    private static function isIpv6Prefix(string $key, int $bits): bool
    {
        $suffix = '/' . $bits;
        if (!str_ends_with($key, $suffix)) {
            return false;
        }
        $address = substr($key, 0, -strlen($suffix));
        $binary = str_contains($address, ':') ? @inet_pton($address) : false;
        if ($binary === false || strlen($binary) !== 16 || inet_ntop($binary) !== $address) {
            return false;
        }

        return substr($binary, intdiv($bits, 8)) === str_repeat("\0", 16 - intdiv($bits, 8));
    }
}
