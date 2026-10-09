<?php

declare(strict_types=1);

namespace LocalAws\Runner;

/**
 * Builds the JSON event that the Lambda SQS event source mapping would send for a batch of messages
 * (https://docs.aws.amazon.com/lambda/latest/dg/with-sqs.html, golden file tests/Fixtures/sqs-events/).
 */
final readonly class SqsEventBuilder
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(private string $region, private string $eventSourceArn) {}

    /**
     * @param list<QueueMessage> $messages
     */
    public function build(array $messages): string
    {
        return json_encode(
            ['Records' => array_map($this->record(...), $messages)],
            self::JSON_FLAGS,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function record(QueueMessage $message): array
    {
        return [
            'messageId' => $message->messageId,
            'receiptHandle' => $message->receiptHandle,
            'body' => $message->body,
            // (object): an empty PHP array would be encoded as the JSON list [], and the event has an object.
            'attributes' => (object) $message->attributes,
            'messageAttributes' => (object) array_map(self::messageAttribute(...), $message->messageAttributes),
            'md5OfBody' => $message->md5OfBody,
            'eventSource' => 'aws:sqs',
            'eventSourceARN' => $this->eventSourceArn,
            'awsRegion' => $this->region,
        ];
    }

    /**
     * @param array{dataType: string, stringValue: ?string, binaryValue: ?string} $attribute
     *
     * @return array<string, mixed>
     */
    private static function messageAttribute(array $attribute): array
    {
        $event = [];
        if ($attribute['stringValue'] !== null) {
            $event['stringValue'] = $attribute['stringValue'];
        }
        if ($attribute['binaryValue'] !== null) {
            $event['binaryValue'] = base64_encode($attribute['binaryValue']);
        }

        return $event + ['stringListValues' => [], 'binaryListValues' => [], 'dataType' => $attribute['dataType']];
    }
}
