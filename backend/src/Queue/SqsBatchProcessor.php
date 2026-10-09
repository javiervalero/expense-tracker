<?php

declare(strict_types=1);

namespace App\Queue;

use App\Infrastructure\Logging\JsonLogger;

/**
 * Lambda SQS batch handling with partial batch responses (ADR 0007, ADR 0012): every record goes to the
 * {@see RecordProcessor} on its own, a record that throws is reported in `batchItemFailures` and the others still
 * run. An event that is not an SQS batch throws {@see MalformedSqsEvent}, which fails the whole invocation.
 */
final readonly class SqsBatchProcessor
{
    private const OWN_NAMESPACE = 'App\\';

    public function __construct(private RecordProcessor $processor, private JsonLogger $logger) {}

    /**
     * @param array<array-key, mixed> $event the decoded Lambda event
     *
     * @return array{batchItemFailures: list<array{itemIdentifier: string}>}
     *
     * @throws MalformedSqsEvent
     */
    public function handle(array $event): array
    {
        $failures = [];
        foreach ($this->records($event) as [$messageId, $record]) {
            try {
                $this->processor->process($record);
            } catch (\Throwable $error) {
                $this->logger->error('record failed', ['messageId' => $messageId, ...$this->describe($error)]);
                $failures[] = ['itemIdentifier' => $messageId];
            }
        }

        return ['batchItemFailures' => $failures];
    }

    /**
     * The message is logged only for the project's own exceptions (see {@see RecordProcessor}): the ones from PHP or
     * a library can quote the payload they failed on.
     *
     * @return array<string, mixed>
     */
    private function describe(\Throwable $error): array
    {
        $described = ['errorClass' => $error::class, 'errorCode' => $error->getCode()];
        if (str_starts_with($error::class, self::OWN_NAMESPACE)) {
            $described['errorMessage'] = $error->getMessage();
        }

        return $described;
    }

    /**
     * The whole batch is validated before the first record runs: a malformed event must not leave half a batch
     * processed and then be redelivered.
     *
     * @param array<array-key, mixed> $event
     *
     * @return list<array{string, array<array-key, mixed>}> (message id, record) pairs, in order
     */
    private function records(array $event): array
    {
        $records = $event['Records'] ?? null;
        if (!\is_array($records) || !array_is_list($records)) {
            throw new MalformedSqsEvent('The event has no "Records" list: it is not an SQS batch.');
        }

        $pairs = [];
        $seen = [];
        foreach ($records as $position => $record) {
            $messageId = \is_array($record) ? ($record['messageId'] ?? null) : null;
            if (!\is_array($record) || !\is_string($messageId) || $messageId === '') {
                throw new MalformedSqsEvent(\sprintf('Record %d is not an SQS record with a messageId.', $position));
            }
            if (isset($seen[$messageId])) {
                throw new MalformedSqsEvent(\sprintf('Record %d has a duplicate messageId (also in record %d).', $position, $seen[$messageId]));
            }
            $seen[$messageId] = $position;
            $pairs[] = [$messageId, $record];
        }

        return $pairs;
    }
}
