<?php

declare(strict_types=1);

namespace App\Infrastructure\Id;

/**
 * Validation of the one id format the application accepts: a lower-case, canonical, version 4 UUID.
 * Anything else (upper case, braces, other versions, the nil UUID, surrounding whitespace) is refused, so an
 * id has exactly one spelling.
 */
final class Uuid
{
    private const PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
