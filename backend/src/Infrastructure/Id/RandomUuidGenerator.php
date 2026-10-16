<?php

declare(strict_types=1);

namespace App\Infrastructure\Id;

/**
 * Random (version 4) UUIDs from the operating system's CSPRNG via `random_bytes`.
 */
final readonly class RandomUuidGenerator implements IdGenerator
{
    public function generate(): string
    {
        $bytes = random_bytes(16);
        // Version 4 in the high nibble of byte 6, variant 10xx in the high bits of byte 8.
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
