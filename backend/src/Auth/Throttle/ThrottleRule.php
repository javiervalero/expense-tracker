<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

use App\Auth\AuthKeys;
use InvalidArgumentException;

/**
 * One fixed-window limit: at most `limit` attempts per `windowSeconds` for the buckets of one scope. The limits are
 * those of A13 (owner decision 5).
 */
final readonly class ThrottleRule
{
    public const int LOGIN_WINDOW_SECONDS = 15 * 60;
    public const int REGISTER_WINDOW_SECONDS = 60 * 60;

    public const int LOGIN_ACCOUNT_LIMIT = 10;
    public const int LOGIN_ADDRESS_LIMIT = 30;
    public const int LOGIN_NETWORK_LIMIT = 100;
    public const int REGISTER_ADDRESS_LIMIT = 5;
    public const int REGISTER_NETWORK_LIMIT = 20;

    /**
     * @throws InvalidArgumentException for an unknown scope, or a limit or window below 1
     */
    public function __construct(
        public string $scope,
        public int $limit,
        public int $windowSeconds,
    ) {
        if (!in_array($scope, AuthKeys::THROTTLE_SCOPES, true)) {
            throw new InvalidArgumentException('Unknown throttle scope.');
        }
        if ($limit < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('A throttle limit and window are at least 1.');
        }
    }

    public static function loginAccount(): self
    {
        return new self(AuthKeys::LOGIN_EMAIL, self::LOGIN_ACCOUNT_LIMIT, self::LOGIN_WINDOW_SECONDS);
    }

    public static function loginAddress(): self
    {
        return new self(AuthKeys::LOGIN_IP, self::LOGIN_ADDRESS_LIMIT, self::LOGIN_WINDOW_SECONDS);
    }

    public static function loginNetwork(): self
    {
        return new self(AuthKeys::LOGIN_NET, self::LOGIN_NETWORK_LIMIT, self::LOGIN_WINDOW_SECONDS);
    }

    public static function registerAddress(): self
    {
        return new self(AuthKeys::REGISTER_IP, self::REGISTER_ADDRESS_LIMIT, self::REGISTER_WINDOW_SECONDS);
    }

    public static function registerNetwork(): self
    {
        return new self(AuthKeys::REGISTER_NET, self::REGISTER_NETWORK_LIMIT, self::REGISTER_WINDOW_SECONDS);
    }
}
