<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

/**
 * An instant after which work should stop, read from a {@see Clock} (so tests move time without sleeping). It does not
 * interrupt a call in flight; code checks {@see self::hasPassed()} between calls.
 */
final readonly class Deadline
{
    private function __construct(private Clock $clock, private \DateTimeImmutable $at) {}

    /** A deadline `$seconds` after the clock's current instant (0: already passed). */
    public static function after(Clock $clock, int $seconds): self
    {
        return new self($clock, $clock->now()->modify(sprintf('%+d seconds', $seconds)));
    }

    /** True at the deadline's instant and after it. */
    public function hasPassed(): bool
    {
        return $this->clock->now() >= $this->at;
    }
}
