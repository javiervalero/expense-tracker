<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Infrastructure\Logging\JsonLogger;
use App\Queue\RecordProcessor;

/**
 * Phase 0 placeholder for the receipt worker: parses the S3 event in the message body and logs which objects
 * arrived. Phase 5 replaces it with the processor that validates, converts and stores the receipt.
 */
final readonly class ReceivedReceiptLogger implements RecordProcessor
{
    public function __construct(private S3EventParser $parser, private JsonLogger $logger) {}

    public function process(array $record): void
    {
        $body = $record['body'] ?? null;
        if (!\is_string($body)) {
            throw new InvalidS3Event('The SQS record has no string body.');
        }

        $messageId = $record['messageId'] ?? null;
        $objects = $this->parser->parse($body);
        if ($objects === []) {
            $this->logger->info('s3 event without objects ignored', ['messageId' => $messageId]);

            return;
        }

        foreach ($objects as $object) {
            $this->logger->info('receipt received', ['messageId' => $messageId, 'bucket' => $object->bucket, 'key' => $object->key]);
        }
    }
}
