<?php

declare(strict_types=1);

namespace LocalAws\Bootstrap;

use LocalAws\Support\LocalEndpoint;

/**
 * Settings of the bootstrap, read from the environment (the Compose service passes them from `.env`).
 *
 * Safety by construction: the S3 endpoint must be a local stand-in and never real AWS (see {@see LocalEndpoint}),
 * so a wrong `.env` can never create buckets in an account. The webhook endpoint (where the emulator sends events,
 * with the auth token) gets the same check.
 */
final readonly class BootstrapSettings
{
    private const DEFAULT_WAIT_SECONDS = 60;
    private const REQUIRED = ['AWS_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'S3_ENDPOINT', 'S3_CORS_ALLOWED_ORIGIN'];
    private const NOTIFICATION_ARN = '/^arn:minio:sqs::[A-Za-z0-9_-]+:webhook\z/';

    /**
     * @param string|null $notificationArn null disables the notification rule (no webhook target is configured)
     */
    private function __construct(
        public string $region,
        public string $accessKeyId,
        public string $secretAccessKey,
        public string $s3Endpoint,
        public string $corsAllowedOrigin,
        public ?string $notificationArn,
        public int $waitTimeoutSeconds,
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
        LocalEndpoint::assertAllowed('S3_ENDPOINT', $environment['S3_ENDPOINT']);
        if (($environment['S3_WEBHOOK_ENDPOINT'] ?? '') !== '') {
            LocalEndpoint::assertAllowed('S3_WEBHOOK_ENDPOINT', $environment['S3_WEBHOOK_ENDPOINT']);
        }
        $notificationArn = ($environment['S3_NOTIFICATION_ARN'] ?? '') === '' ? null : $environment['S3_NOTIFICATION_ARN'];
        if ($notificationArn !== null && preg_match(self::NOTIFICATION_ARN, $notificationArn) !== 1) {
            throw new \RuntimeException('S3_NOTIFICATION_ARN must look like arn:minio:sqs::<ID>:webhook (the emulator\'s webhook target).');
        }

        return new self(
            $environment['AWS_REGION'],
            $environment['AWS_ACCESS_KEY_ID'],
            $environment['AWS_SECRET_ACCESS_KEY'],
            $environment['S3_ENDPOINT'],
            $environment['S3_CORS_ALLOWED_ORIGIN'],
            $notificationArn,
            self::waitTimeout($environment['BOOTSTRAP_WAIT_SECONDS'] ?? ''),
        );
    }

    private static function waitTimeout(string $value): int
    {
        if ($value === '') {
            return self::DEFAULT_WAIT_SECONDS;
        }
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new \RuntimeException('BOOTSTRAP_WAIT_SECONDS must be a positive whole number of seconds.');
        }

        return (int) $value;
    }
}
