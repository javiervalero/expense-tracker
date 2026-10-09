<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Sqs\SqsClient;

/**
 * AWS SDK clients for the local stand-ins (DynamoDB Local, ElasticMQ), built only from environment values.
 *
 * Safety by construction: an explicit endpoint is mandatory for every service and one that is not a local
 * host (and in particular one that points at real AWS) is refused, so a test run can never reach (or bill) a real account. Credentials are always passed
 * explicitly, so the SDK never falls back to the default provider chain (profiles, instance metadata).
 */
final readonly class LocalAws
{
    private const ENDPOINT_VARIABLES = ['SQS_ENDPOINT', 'DYNAMODB_ENDPOINT'];
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
