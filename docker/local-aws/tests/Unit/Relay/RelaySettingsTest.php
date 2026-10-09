<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Relay;

use LocalAws\Relay\RelaySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelaySettingsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        return [
            'AWS_REGION' => 'eu-west-1',
            'AWS_ACCESS_KEY_ID' => 'localdev',
            'AWS_SECRET_ACCESS_KEY' => 'localdevsecret',
            'SQS_ENDPOINT' => 'http://sqs:9324',
            'SQS_RECEIPTS_QUEUE_URL' => 'http://sqs:9324/000000000000/receipts',
            'S3_WEBHOOK_AUTH_TOKEN' => 'localdev-webhook-token',
        ];
    }

    public function testReadsEveryValueFromTheEnvironment(): void
    {
        $settings = RelaySettings::fromEnvironment(self::environment());

        self::assertSame('eu-west-1', $settings->region);
        self::assertSame('localdev', $settings->accessKeyId);
        self::assertSame('localdevsecret', $settings->secretAccessKey);
        self::assertSame('http://sqs:9324', $settings->sqsEndpoint);
        self::assertSame('http://sqs:9324/000000000000/receipts', $settings->queueUrl);
        self::assertSame('localdev-webhook-token', $settings->authToken);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredVariables(): iterable
    {
        foreach (['AWS_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'SQS_ENDPOINT', 'SQS_RECEIPTS_QUEUE_URL', 'S3_WEBHOOK_AUTH_TOKEN'] as $variable) {
            yield $variable => [$variable];
        }
    }

    #[DataProvider('requiredVariables')]
    public function testRefusesAMissingOrEmptyRequiredVariableNamingIt(string $variable): void
    {
        foreach ([null, ''] as $value) {
            $environment = self::environment();
            unset($environment[$variable]);
            if ($value !== null) {
                $environment[$variable] = $value;
            }

            try {
                RelaySettings::fromEnvironment($environment);
                self::fail('expected an exception for ' . $variable);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($variable, $e->getMessage());
            }
        }
    }

    #[DataProvider('refusedEndpoints')]
    public function testRefusesAnEndpointOrQueueUrlThatIsNotALocalStandIn(string $variable, string $value): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($variable);

        RelaySettings::fromEnvironment([$variable => $value] + self::environment());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedEndpoints(): iterable
    {
        foreach (['SQS_ENDPOINT', 'SQS_RECEIPTS_QUEUE_URL'] as $variable) {
            yield "$variable real AWS" => [$variable, 'https://sqs.eu-west-1.amazonaws.com/123456789012/receipts'];
            yield "$variable public host" => [$variable, 'http://example.com:9324/000000000000/receipts'];
            yield "$variable hidden by credentials" => [$variable, 'http://sqs.amazonaws.com@sqs:9324/q'];
            yield "$variable no scheme" => [$variable, 'sqs:9324'];
            yield "$variable not http" => [$variable, 'ftp://sqs:9324/q'];
        }
    }

    #[DataProvider('acceptedHosts')]
    public function testAcceptsLoopbackTheDockerHostAliasAndComposeServiceNames(string $url): void
    {
        $settings = RelaySettings::fromEnvironment(['SQS_ENDPOINT' => $url, 'SQS_RECEIPTS_QUEUE_URL' => $url . '/000000000000/receipts'] + self::environment());

        self::assertSame($url, $settings->sqsEndpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedHosts(): iterable
    {
        yield 'localhost' => ['http://localhost:9324'];
        yield 'ipv4 loopback' => ['http://127.0.0.1:9324'];
        yield 'ipv6 loopback' => ['http://[::1]:9324'];
        yield 'docker host alias' => ['http://host.docker.internal:9324'];
        yield 'compose service' => ['http://sqs:9324'];
    }
}
