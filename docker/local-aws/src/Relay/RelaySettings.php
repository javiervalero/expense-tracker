<?php

declare(strict_types=1);

namespace LocalAws\Relay;

use LocalAws\Support\LocalEndpoint;

/**
 * Settings of the S3 events relay, read from the environment (the Compose service passes them from `.env`).
 *
 * The SQS endpoint and the queue URL must be local stand-ins, never real AWS (see {@see LocalEndpoint}).
 */
final readonly class RelaySettings
{
    private const REQUIRED = ['AWS_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'SQS_ENDPOINT', 'SQS_RECEIPTS_QUEUE_URL', 'S3_WEBHOOK_AUTH_TOKEN'];

    private function __construct(
        public string $region,
        public string $accessKeyId,
        public string $secretAccessKey,
        public string $sqsEndpoint,
        public string $queueUrl,
        public string $authToken,
    ) {}

    /**
     * @param array<string, string> $environment
     *
     * @throws \RuntimeException when a value is missing, malformed or points at real AWS
     */
    public static function fromEnvironment(array $environment): self
    {
        foreach (self::REQUIRED as $variable) {
            if (($environment[$variable] ?? '') === '') {
                throw new \RuntimeException(sprintf('%s is not set (see .env.example).', $variable));
            }
        }
        LocalEndpoint::assertAllowed('SQS_ENDPOINT', $environment['SQS_ENDPOINT']);
        LocalEndpoint::assertAllowed('SQS_RECEIPTS_QUEUE_URL', $environment['SQS_RECEIPTS_QUEUE_URL']);

        return new self(
            $environment['AWS_REGION'],
            $environment['AWS_ACCESS_KEY_ID'],
            $environment['AWS_SECRET_ACCESS_KEY'],
            $environment['SQS_ENDPOINT'],
            $environment['SQS_RECEIPTS_QUEUE_URL'],
            $environment['S3_WEBHOOK_AUTH_TOKEN'],
        );
    }
}
