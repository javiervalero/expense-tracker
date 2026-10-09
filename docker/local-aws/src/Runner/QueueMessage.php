<?php

declare(strict_types=1);

namespace LocalAws\Runner;

/**
 * One message as ReceiveMessage returns it, reduced to what the Lambda SQS event needs.
 *
 * `attributes` are the system attributes (ApproximateReceiveCount, SentTimestamp, ...); `messageAttributes` map a name
 * to its data type and to a string or a raw binary value.
 */
final readonly class QueueMessage
{
    /**
     * @param array<string, string> $attributes
     * @param array<string, array{dataType: string, stringValue: ?string, binaryValue: ?string}> $messageAttributes
     */
    public function __construct(
        public string $messageId,
        public string $receiptHandle,
        public string $body,
        public string $md5OfBody,
        public array $attributes,
        public array $messageAttributes,
    ) {}
}
