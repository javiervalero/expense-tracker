<?php

declare(strict_types=1);

namespace LocalAws\Bootstrap;

/**
 * Settings of the bootstrap, read from the environment (the Compose service passes them from `.env`).
 *
 * Safety by construction: the S3 endpoint must be a local stand-in (loopback, the Docker host alias or a
 * single-label Compose service name) and never real AWS, so a wrong `.env` can never create buckets in an account.
 * The webhook endpoint (where the emulator sends events, with the auth token) gets the same check.
 *
 * KEEP IN SYNC: the endpoint allowlist (LOCAL_HOSTS, COMPOSE_SERVICE_NAME, REAL_AWS_SUFFIXES) mirrors the one in
 * backend/tests/Support/LocalAws.php. Both are separate on purpose (separate Composer projects); a change to one
 * must be made in the other.
 */
final readonly class BootstrapSettings
{
    private const DEFAULT_WAIT_SECONDS = 60;
    private const REQUIRED = ['AWS_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'S3_ENDPOINT', 'S3_CORS_ALLOWED_ORIGIN'];
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', 'host.docker.internal'];
    private const COMPOSE_SERVICE_NAME = '/^[a-z0-9-]+$/';
    private const NOTIFICATION_ARN = '/^arn:minio:sqs::[A-Za-z0-9_-]+:webhook\z/';
    private const REAL_AWS_SUFFIXES = ['.amazonaws.com', '.amazonaws.com.cn', '.api.aws'];

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
        self::assertLocalEndpoint('S3_ENDPOINT', $environment['S3_ENDPOINT']);
        if (($environment['S3_WEBHOOK_ENDPOINT'] ?? '') !== '') {
            self::assertLocalEndpoint('S3_WEBHOOK_ENDPOINT', $environment['S3_WEBHOOK_ENDPOINT']);
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

    private static function assertLocalEndpoint(string $variable, string $endpoint): void
    {
        $parts = parse_url($endpoint);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if ($host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            throw new \RuntimeException(sprintf('%s is not an http(s) URL with a host.', $variable));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException(sprintf('%s must not contain credentials (user@host): the real host could be hidden.', $variable));
        }

        $normalisedHost = strtolower(rtrim($host, '.'));
        foreach (self::REAL_AWS_SUFFIXES as $suffix) {
            if ($normalisedHost === ltrim($suffix, '.') || str_ends_with($normalisedHost, $suffix)) {
                throw new \RuntimeException(sprintf('%s points at real AWS (%s); the bootstrap only configures local stand-ins.', $variable, $host));
            }
        }
        if (!in_array($normalisedHost, self::LOCAL_HOSTS, true) && preg_match(self::COMPOSE_SERVICE_NAME, $normalisedHost) !== 1) {
            throw new \RuntimeException(sprintf(
                '%s host "%s" is not an allowed local stand-in (localhost, loopback, host.docker.internal or a Compose service name).',
                $variable,
                $host,
            ));
        }
    }
}
