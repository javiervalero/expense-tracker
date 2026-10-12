<?php

declare(strict_types=1);

namespace LocalAws\Runner;

use Aws\Sqs\SqsClient;

/**
 * The receipts queue (ElasticMQ locally) through the AWS SDK. Errors from the SDK are not caught here: the runner's
 * loop logs them and backs off.
 */
final readonly class SqsQueueClient implements QueueClient
{
    /** Long polling, as the Lambda event source mapping does. */
    public const WAIT_TIME_SECONDS = 20;
    /** The most SQS returns at once, and the default Lambda batch size. */
    public const MAX_MESSAGES = 10;

    public function __construct(private SqsClient $client, private string $queueUrl) {}

    public function receive(): array
    {
        $result = $this->client->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => self::MAX_MESSAGES,
            'WaitTimeSeconds' => self::WAIT_TIME_SECONDS,
            'AttributeNames' => ['All'],
            'MessageAttributeNames' => ['All'],
        ]);

        // The SDK has already parsed (and MD5-checked) the answer, so every entry is a structure.
        return array_values(array_map(self::toMessage(...), array_filter((array) ($result->get('Messages') ?? []), is_array(...))));
    }

    public function delete(array $messages): array
    {
        if ($messages === []) {
            return [];
        }
        // The batch entry id is the position, always valid for SQS; it maps a failure back to the message.
        $entries = [];
        foreach ($messages as $position => $message) {
            $entries[] = ['Id' => (string) $position, 'ReceiptHandle' => $message->receiptHandle];
        }
        $result = $this->client->deleteMessageBatch(['QueueUrl' => $this->queueUrl, 'Entries' => $entries]);
        $failed = $result->get('Failed') ?? [];

        $undeleted = [];
        foreach (is_array($failed) ? $failed : [] as $failure) {
            $id = is_array($failure) ? ($failure['Id'] ?? null) : null;
            $message = is_string($id) ? ($messages[(int) $id] ?? null) : null;
            // (string) (int) $id !== $id rejects ids that are not plain decimal positions ("01", "1.5", " 1"): we only ever send those.
            // An answer that names an entry we did not send cannot be matched: report the whole batch.
            if ($message === null || (string) (int) $id !== $id) {
                return array_map(static fn(QueueMessage $m): string => $m->messageId, $messages);
            }
            $undeleted[$message->messageId] = $message->messageId;
        }

        return array_values($undeleted);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function toMessage(array $raw): QueueMessage
    {
        $text = static function (string $field) use ($raw): string {
            $value = $raw[$field] ?? null;
            if (!is_string($value)) {
                throw new \UnexpectedValueException(sprintf('A received message has no %s.', $field));
            }

            return $value;
        };

        return new QueueMessage(
            $text('MessageId'),
            $text('ReceiptHandle'),
            $text('Body'),
            $text('MD5OfBody'),
            self::stringMap($raw['Attributes'] ?? []),
            self::messageAttributes($raw['MessageAttributes'] ?? []),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $values): array
    {
        $map = [];
        foreach (is_array($values) ? $values : [] as $name => $value) {
            if (is_string($value)) {
                $map[(string) $name] = $value;
            }
        }

        return $map;
    }

    /**
     * @return array<string, array{dataType: string, stringValue: ?string, binaryValue: ?string}>
     */
    private static function messageAttributes(mixed $values): array
    {
        $attributes = [];
        foreach (array_filter(is_array($values) ? $values : [], is_array(...)) as $name => $value) {
            $attributes[(string) $name] = [
                'dataType' => is_string($value['DataType'] ?? null) ? $value['DataType'] : 'String',
                'stringValue' => is_string($value['StringValue'] ?? null) ? $value['StringValue'] : null,
                'binaryValue' => is_string($value['BinaryValue'] ?? null) ? $value['BinaryValue'] : null,
            ];
        }

        return $attributes;
    }
}
