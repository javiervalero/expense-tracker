<?php

declare(strict_types=1);

namespace LocalAws\Runner;

/**
 * What the function endpoint answered: the HTTP status and the raw body (not interpreted yet).
 */
final readonly class InvocationResult
{
    public function __construct(public int $status, public string $body) {}
}
