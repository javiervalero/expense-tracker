<?php

declare(strict_types=1);

namespace LocalAws\Runner;

use LocalAws\Support\LocalEndpoint;

/**
 * Settings of the worker runner, read from the environment (the Compose service passes them).
 *
 * The SQS endpoint, the queue URL and the function endpoint must be local stand-ins, never real AWS (see
 * {@see LocalEndpoint}).
 *
 * `eventSourceArn` is a LOCAL PLACEHOLDER: ElasticMQ has no real account, so the ARN uses the all-zero account id of
 * its queue URLs (`arn:aws:sqs:<region>:000000000000:<queue name>`). The worker must not depend on its value.
 */
final readonly class RunnerSettings
{
    private const REQUIRED = ['AWS_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'SQS_ENDPOINT', 'SQS_RECEIPTS_QUEUE_URL', 'WORKER_INVOCATION_URL'];
    private const PLACEHOLDER_ACCOUNT_ID = '000000000000';
    /** SQS queue names: letters, digits, hyphens and underscores, at most 80 characters. */
    private const QUEUE_NAME = '/^[A-Za-z0-9_-]{1,80}\z/';

    private function __construct(
        public string $region,
        public string $accessKeyId,
        public string $secretAccessKey,
        public string $sqsEndpoint,
        public string $queueUrl,
        public string $invocationUrl,
        public string $eventSourceArn,
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
                throw new \RuntimeException(sprintf('%s is not set (see docker-compose.yml and .env.example).', $variable));
            }
        }
        foreach (['SQS_ENDPOINT', 'SQS_RECEIPTS_QUEUE_URL', 'WORKER_INVOCATION_URL'] as $variable) {
            LocalEndpoint::assertAllowed($variable, $environment[$variable]);
        }

        return new self(
            $environment['AWS_REGION'],
            $environment['AWS_ACCESS_KEY_ID'],
            $environment['AWS_SECRET_ACCESS_KEY'],
            $environment['SQS_ENDPOINT'],
            $environment['SQS_RECEIPTS_QUEUE_URL'],
            $environment['WORKER_INVOCATION_URL'],
            sprintf(
                'arn:aws:sqs:%s:%s:%s',
                $environment['AWS_REGION'],
                self::PLACEHOLDER_ACCOUNT_ID,
                self::queueName($environment['SQS_RECEIPTS_QUEUE_URL']),
            ),
        );
    }

    private static function queueName(string $queueUrl): string
    {
        $path = parse_url($queueUrl, PHP_URL_PATH);
        $segments = is_string($path) ? explode('/', rtrim($path, '/')) : [];
        $name = $segments === [] ? '' : $segments[array_key_last($segments)];
        if (preg_match(self::QUEUE_NAME, $name) !== 1) {
            throw new \RuntimeException('SQS_RECEIPTS_QUEUE_URL does not end with a valid queue name.');
        }

        return $name;
    }
}
