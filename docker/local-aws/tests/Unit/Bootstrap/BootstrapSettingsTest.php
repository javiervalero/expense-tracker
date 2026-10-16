<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Bootstrap;

use LocalAws\Bootstrap\BootstrapSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BootstrapSettingsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function validEnvironment(): array
    {
        return [
            'AWS_REGION' => 'eu-west-1',
            'AWS_ACCESS_KEY_ID' => 'localdev',
            'AWS_SECRET_ACCESS_KEY' => 'localdevsecret',
            'S3_ENDPOINT' => 'http://s3:9000',
            'S3_CORS_ALLOWED_ORIGIN' => 'http://localhost:5173',
            'S3_NOTIFICATION_ARN' => 'arn:minio:sqs::RELAY:webhook',
            'S3_WEBHOOK_ENDPOINT' => 'http://s3-events-relay:8080/',
            'DYNAMODB_ENDPOINT' => 'http://dynamodb:8000',
            'DYNAMODB_TABLE' => 'expense-tracker-local',
        ];
    }

    public function testReadsEveryValueFromTheEnvironment(): void
    {
        $settings = BootstrapSettings::fromEnvironment(self::validEnvironment() + ['BOOTSTRAP_WAIT_SECONDS' => '90']);

        self::assertSame('eu-west-1', $settings->region);
        self::assertSame('localdev', $settings->accessKeyId);
        self::assertSame('localdevsecret', $settings->secretAccessKey);
        self::assertSame('http://s3:9000', $settings->s3Endpoint);
        self::assertSame('http://localhost:5173', $settings->corsAllowedOrigin);
        self::assertSame('arn:minio:sqs::RELAY:webhook', $settings->notificationArn);
        self::assertSame(90, $settings->waitTimeoutSeconds);
        self::assertSame('http://dynamodb:8000', $settings->dynamoDbEndpoint);
        self::assertSame('expense-tracker-local', $settings->dynamoDbTable);
    }

    #[DataProvider('refusedEndpoints')]
    public function testRefusesADynamoDbEndpointThatIsNotAnAllowedLocalStandIn(string $endpoint): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DYNAMODB_ENDPOINT');

        BootstrapSettings::fromEnvironment(['DYNAMODB_ENDPOINT' => $endpoint] + self::validEnvironment());
    }

    public function testRefusesTheRealAwsDynamoDbEndpoint(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DYNAMODB_ENDPOINT');

        BootstrapSettings::fromEnvironment(['DYNAMODB_ENDPOINT' => 'https://dynamodb.eu-west-1.amazonaws.com'] + self::validEnvironment());
    }

    #[DataProvider('invalidTableNames')]
    public function testRefusesATableNameDynamoDbWouldNotAccept(string $name): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DYNAMODB_TABLE');

        BootstrapSettings::fromEnvironment(['DYNAMODB_TABLE' => $name] + self::validEnvironment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTableNames(): iterable
    {
        yield 'too short' => ['ab'];
        yield 'too long' => [str_repeat('a', 256)];
        yield 'space' => ['my table'];
        yield 'slash' => ['a/b'];
    }

    public function testNotificationIsDisabledWhenTheArnIsEmptyOrAbsent(): void
    {
        $empty = BootstrapSettings::fromEnvironment(['S3_NOTIFICATION_ARN' => ''] + self::validEnvironment());
        $environment = self::validEnvironment();
        unset($environment['S3_NOTIFICATION_ARN']);
        $absent = BootstrapSettings::fromEnvironment($environment);

        self::assertNull($empty->notificationArn);
        self::assertNull($absent->notificationArn);
    }

    public function testWaitTimeoutDefaultsTo60Seconds(): void
    {
        self::assertSame(60, BootstrapSettings::fromEnvironment(self::validEnvironment())->waitTimeoutSeconds);
    }

    #[DataProvider('requiredVariables')]
    public function testThrowsWhenARequiredVariableIsMissingOrEmpty(string $variable): void
    {
        $missing = self::validEnvironment();
        unset($missing[$variable]);
        $empty = [$variable => ''] + self::validEnvironment();

        foreach ([$missing, $empty] as $environment) {
            try {
                BootstrapSettings::fromEnvironment($environment);
                self::fail("{$variable} must be required");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($variable, $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredVariables(): iterable
    {
        foreach (['AWS_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'S3_ENDPOINT', 'S3_CORS_ALLOWED_ORIGIN', 'DYNAMODB_ENDPOINT', 'DYNAMODB_TABLE'] as $variable) {
            yield $variable => [$variable];
        }
    }

    #[DataProvider('invalidWaitTimeouts')]
    public function testRejectsAWaitTimeoutThatIsNotAPositiveInteger(string $value): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BOOTSTRAP_WAIT_SECONDS');

        BootstrapSettings::fromEnvironment(['BOOTSTRAP_WAIT_SECONDS' => $value] + self::validEnvironment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidWaitTimeouts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'text' => ['soon'];
        yield 'decimal' => ['1.5'];
    }

    #[DataProvider('refusedEndpoints')]
    public function testRefusesAnEndpointThatIsNotAnAllowedLocalStandIn(string $endpoint): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3_ENDPOINT');

        BootstrapSettings::fromEnvironment(['S3_ENDPOINT' => $endpoint] + self::validEnvironment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedEndpoints(): iterable
    {
        yield 'real AWS regional' => ['https://s3.eu-west-1.amazonaws.com'];
        yield 'real AWS global' => ['https://s3.amazonaws.com'];
        yield 'AWS China' => ['https://s3.cn-north-1.amazonaws.com.cn'];
        yield 'credentials hide the host' => ['http://s3:9000@s3.amazonaws.com'];
        yield 'public host' => ['http://example.com'];
        yield 'not a URL' => ['s3'];
        yield 'ftp scheme' => ['ftp://s3:9000'];
    }

    #[DataProvider('allowedEndpoints')]
    public function testAcceptsLoopbackAndComposeServiceNames(string $endpoint): void
    {
        $settings = BootstrapSettings::fromEnvironment(['S3_ENDPOINT' => $endpoint] + self::validEnvironment());

        self::assertSame($endpoint, $settings->s3Endpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedEndpoints(): iterable
    {
        yield 'compose service' => ['http://s3:9000'];
        yield 'localhost' => ['http://localhost:9000'];
        yield 'loopback' => ['http://127.0.0.1:9000'];
        yield 'docker host alias' => ['http://host.docker.internal:9000'];
    }

    #[DataProvider('refusedWebhookEndpoints')]
    public function testRefusesAWebhookEndpointThatIsNotAnAllowedLocalStandIn(string $endpoint): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3_WEBHOOK_ENDPOINT');

        BootstrapSettings::fromEnvironment(['S3_WEBHOOK_ENDPOINT' => $endpoint] + self::validEnvironment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedWebhookEndpoints(): iterable
    {
        yield 'public host' => ['https://evil.example/hook'];
        yield 'real AWS' => ['https://abc.execute-api.eu-west-1.amazonaws.com/'];
        yield 'credentials hide the host' => ['http://relay:8080@evil.example/'];
        yield 'not a URL' => ['relay'];
        yield 'ftp scheme' => ['ftp://relay:8080'];
        yield 'dotted lookalike' => ['http://s3-events-relay.evil.example:8080/'];
    }

    #[DataProvider('allowedWebhookEndpoints')]
    public function testAcceptsLocalWebhookEndpoints(string $endpoint): void
    {
        $settings = BootstrapSettings::fromEnvironment(['S3_WEBHOOK_ENDPOINT' => $endpoint] + self::validEnvironment());

        self::assertSame('eu-west-1', $settings->region);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedWebhookEndpoints(): iterable
    {
        yield 'relay service' => ['http://s3-events-relay:8080/'];
        yield 'localhost' => ['http://localhost:8080/'];
        yield 'docker host alias' => ['http://host.docker.internal:8080/'];
    }

    public function testTheWebhookEndpointIsOptionalWhenTheNotificationRuleIsNotManaged(): void
    {
        $environment = ['S3_NOTIFICATION_ARN' => ''] + self::validEnvironment();
        unset($environment['S3_WEBHOOK_ENDPOINT']);

        self::assertNull(BootstrapSettings::fromEnvironment($environment)->notificationArn);
    }

    #[DataProvider('invalidNotificationArns')]
    public function testRefusesANotificationArnThatIsNotAWebhookTargetArn(string $arn): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3_NOTIFICATION_ARN');

        BootstrapSettings::fromEnvironment(['S3_NOTIFICATION_ARN' => $arn] + self::validEnvironment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNotificationArns(): iterable
    {
        yield 'real SQS ARN' => ['arn:aws:sqs:eu-west-1:123456789012:receipts'];
        yield 'missing id' => ['arn:minio:sqs:::webhook'];
        yield 'wrong target type' => ['arn:minio:sqs::RELAY:amqp'];
        yield 'lowercase prefix typo' => ['arn:minio:sns::RELAY:webhook'];
        yield 'trailing junk' => ['arn:minio:sqs::RELAY:webhook extra'];
        yield 'plain text' => ['relay'];
    }
}
