<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

/**
 * Typed access to the JSON that the tests decode (keeps PHPStan's max level satisfied without noise in the tests).
 */
final class Decoded
{
    /**
     * @return array<array-key, mixed>
     */
    public static function object(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Expected a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return list<array<array-key, mixed>> the `Records` of an SQS event
     */
    public static function records(string $json): array
    {
        $records = self::object($json)['Records'] ?? null;
        if (!is_array($records)) {
            throw new \UnexpectedValueException('The event has no Records.');
        }

        return array_values(array_filter($records, is_array(...)));
    }
}
