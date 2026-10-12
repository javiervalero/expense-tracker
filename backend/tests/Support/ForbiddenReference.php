<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * One forbidden reference found by {@see ForbiddenReferenceScanner}.
 */
final readonly class ForbiddenReference implements \Stringable
{
    public function __construct(
        public string $file,
        public int $line,
        public string $rule,
        public string $match,
    ) {}

    public function __toString(): string
    {
        return sprintf('%s:%d: %s (%s)', $this->file, $this->line, $this->rule, $this->match);
    }
}
