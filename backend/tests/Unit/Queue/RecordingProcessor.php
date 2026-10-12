<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Queue\RecordProcessor;

/**
 * Test double: remembers the message id of every record it was given, and refuses the ones it was told to.
 */
final class RecordingProcessor implements RecordProcessor
{
    /** @var list<string> */
    private array $seen = [];

    /**
     * @param list<string> $failingIds
     */
    public function __construct(private readonly array $failingIds = []) {}

    public function process(array $record): void
    {
        $id = $record['messageId'] ?? null;
        \assert(\is_string($id));
        $this->seen[] = $id;
        if (\in_array($id, $this->failingIds, true)) {
            throw new RecordRejected('cannot process ' . $id);
        }
    }

    /**
     * @return list<string>
     */
    public function seen(): array
    {
        return $this->seen;
    }
}
