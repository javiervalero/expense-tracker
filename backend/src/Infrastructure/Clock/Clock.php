<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

/**
 * The source of "now". Code that depends on time takes a Clock, so tests can freeze and move it.
 */
interface Clock
{
    /**
     * The current instant, in UTC.
     */
    public function now(): \DateTimeImmutable;
}
