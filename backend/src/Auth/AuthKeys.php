<?php

declare(strict_types=1);

namespace App\Auth;

use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\Id\Uuid;
use InvalidArgumentException;

/**
 * The primary keys of the authentication items (single table, ADR 0002). A key is never parsed back, so a `#` inside an
 * e-mail address is harmless. User ids come from a session principal or a freshly generated id, never from the request.
 */
final class AuthKeys
{
    private const SHA256_HEX = '/\A[0-9a-f]{64}\z/D';

    /**
     * @return array{PK: string, SK: string}
     *
     * @throws InvalidArgumentException when the id is not a lower-case canonical UUID
     */
    public static function user(string $userId): array
    {
        if (!Uuid::isValid($userId)) {
            throw new InvalidArgumentException('A user id is a lower-case UUID.');
        }

        return [TableLayout::PK => 'USER#' . $userId, TableLayout::SK => 'PROFILE'];
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

        return [TableLayout::PK => 'SESSION#' . $tokenHash, TableLayout::SK => 'SESSION'];
    }
}
