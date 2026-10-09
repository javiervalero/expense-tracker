<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Aws\DynamoDb\DynamoDbClient;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;

/**
 * AWS SDK clients for the local stand-ins (DynamoDB Local, ElasticMQ, the S3 emulator), built only from environment values.
 *
 * Safety by construction: an explicit endpoint is mandatory for every service and one that is not a local
 * host (and in particular one that points at real AWS) is refused, so a test run can never reach (or bill) a real account. Credentials are always passed
 * explicitly, so the SDK never falls back to the default provider chain (profiles, instance metadata).
 *
 * KEEP IN SYNC: the endpoint allowlist (LOCAL_HOSTS, COMPOSE_SERVICE_NAME and the real-AWS suffixes) mirrors the
 * one in docker/local-aws/src/Bootstrap/BootstrapSettings.php. They are separate on purpose (separate Composer
 * projects); a change to one must be made in the other.
 */
final readonly class LocalAws
{
    private const ENDPOINT_VARIABLES = ['SQS_ENDPOINT', 'DYNAMODB_ENDPOINT', 'S3_ENDPOINT', 'S3_PUBLIC_ENDPOINT'];
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', 'host.docker.internal'];
    private const COMPOSE_SERVICE_NAME = '/^[a-z0-9-]+$/';
    private const SETTING_VARIABLES = ['AWS_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'];

    /**
     * @param array<string, string> $environment already validated by {@see self::fromEnvironment()}
     */
    private function __construct(private array $environment) {}

    /**
     * @param array<string, string> $environment
     *
     * @throws \RuntimeException when a setting or an endpoint is missing, malformed or points at real AWS
     */
    public static function fromEnvironment(array $environment): self
    {
        foreach ([...self::ENDPOINT_VARIABLES, ...self::SETTING_VARIABLES] as $variable) {
            if (($environment[$variable] ?? '') === '') {
                throw new \RuntimeException(sprintf('%s is not set: the local AWS stand-ins need an explicit value (see .env.example).', $variable));
            }
        }

        foreach (self::ENDPOINT_VARIABLES as $variable) {
            self::assertLocalEndpoint($variable, $environment[$variable]);
        }

        return new self($environment);
    }

    public static function fromGlobals(): self
    {
        $environment = [];
        foreach ([...self::ENDPOINT_VARIABLES, ...self::SETTING_VARIABLES] as $variable) {
            $value = getenv($variable);
            if ($value !== false) {
                $environment[$variable] = $value;
            }
        }

        return self::fromEnvironment($environment);
    }

    public function sqs(): SqsClient
    {
        return new SqsClient($this->clientConfig('SQS_ENDPOINT', '2012-11-05'));
    }

    public function dynamoDb(): DynamoDbClient
    {
        return new DynamoDbClient($this->clientConfig('DYNAMODB_ENDPOINT', '2012-08-10'));
    }

    /**
     * S3 as seen from inside the Compose network (`S3_ENDPOINT`, for example http://s3:9000). Path-style addressing,
     * which the single-host emulator needs.
     */
    public function s3(): S3Client
    {
        return new S3Client($this->s3Config('S3_ENDPOINT'));
    }

    /**
     * S3 as the browser sees it (`S3_PUBLIC_ENDPOINT`, for example http://localhost:9000): for URLs that are signed
     * for the host-facing address (presigned GET), where the signature covers the Host header.
     */
    public function s3ForPublicEndpoint(): S3Client
    {
        return new S3Client($this->s3Config('S3_PUBLIC_ENDPOINT'));
    }

    public function s3Endpoint(): string
    {
        return $this->environment['S3_ENDPOINT'];
    }

    public function s3PublicEndpoint(): string
    {
        return $this->environment['S3_PUBLIC_ENDPOINT'];
    }

    /**
     * @return array<string, mixed>
     */
    private function s3Config(string $endpointVariable): array
    {
        return $this->clientConfig($endpointVariable, '2006-03-01') + ['use_path_style_endpoint' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientConfig(string $endpointVariable, string $version): array
    {
        return [
            'version' => $version,
            'region' => $this->environment['AWS_REGION'],
            'endpoint' => $this->environment[$endpointVariable],
            'credentials' => [
                'key' => $this->environment['AWS_ACCESS_KEY_ID'],
                'secret' => $this->environment['AWS_SECRET_ACCESS_KEY'],
            ],
        ];
    }

    /**
     * Allowlist first (only loopback, the Docker host alias and single-label Compose service names), then a
     * second, explicit layer that names real AWS so the failure message is clear.
     */
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
        if ($normalisedHost === 'amazonaws.com' || str_ends_with($normalisedHost, '.amazonaws.com')
            || str_ends_with($normalisedHost, '.amazonaws.com.cn') || str_ends_with($normalisedHost, '.api.aws')) {
            throw new \RuntimeException(sprintf('%s points at real AWS (%s); tests may only use local stand-ins.', $variable, $host));
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
