<?php

declare(strict_types=1);

namespace LocalAws\Relay;

/**
 * What the relay answers: status code, body and extra headers. A value; the router script writes it out.
 */
final readonly class RelayResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = [],
    ) {}
}
