<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

/**
 * The real clock, always in UTC whatever the default timezone of the PHP process is.
 */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
