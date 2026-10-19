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
}
