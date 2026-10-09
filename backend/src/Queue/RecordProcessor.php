<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Handles one SQS record. Throwing any Throwable marks that record (and only that record) as failed:
 * {@see SqsBatchProcessor} reports it in `batchItemFailures` and SQS delivers it again.
 *
 * Contract: an implementation must never put payload content (the body, a merchant, an amount, a file name) into
 * the message of an exception it throws. {@see SqsBatchProcessor} logs the message of exceptions from the project's
 * own namespace (`App\`) only; for any other exception (TypeError, JsonException, SDK errors) it logs the class and
 * code, since their messages can quote the data they choked on.
 */
interface RecordProcessor
{
    /**
     * @param array<array-key, mixed> $record one element of the event's `Records` (messageId, body, ...)
     */
    public function process(array $record): void;
}
