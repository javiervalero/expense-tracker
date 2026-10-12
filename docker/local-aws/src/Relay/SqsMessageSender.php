<?php

declare(strict_types=1);

namespace LocalAws\Relay;

use Aws\Sqs\SqsClient;

/**
 * Sends each event as one message to one SQS queue (ElasticMQ locally).
 */
final readonly class SqsMessageSender implements MessageSender
{
    public function __construct(private SqsClient $client, private string $queueUrl) {}

    public function send(string $body): void
    {
        try {
            $this->client->sendMessage(['QueueUrl' => $this->queueUrl, 'MessageBody' => $body]);
        } catch (\Throwable $failure) {
            throw new MessageSendFailed('sending to the queue failed: ' . $failure->getMessage(), 0, $failure);
        }
    }
}
