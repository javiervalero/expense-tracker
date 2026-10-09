<?php

declare(strict_types=1);

namespace LocalAws\Runner;

/**
 * The outcome of one invocation: either the whole batch failed (nothing may be deleted), or these messages failed
 * and all the others succeeded.
 */
final readonly class BatchVerdict
{
    /**
     * @param list<string> $failedMessageIds
     */
    private function __construct(public bool $wholeBatchFailed, public string $reason, public array $failedMessageIds) {}

    /**
     * @param string $reason a short text for the log; it never contains message bodies
     */
    public static function wholeBatchFailed(string $reason): self
    {
        return new self(true, $reason, []);
    }

    /**
     * @param list<string> $failedMessageIds empty when every message succeeded
     */
    public static function partial(array $failedMessageIds): self
    {
        return new self(false, '', $failedMessageIds);
    }
}
