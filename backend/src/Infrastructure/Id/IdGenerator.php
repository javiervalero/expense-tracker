<?php

declare(strict_types=1);

namespace App\Infrastructure\Id;

/**
 * The source of new identifiers. Identifiers are opaque UUIDs (ADR 0002).
 */
interface IdGenerator
{
    /**
     * A new, unpredictable id in the form accepted by {@see Uuid::isValid()}.
     */
    public function generate(): string;
}
