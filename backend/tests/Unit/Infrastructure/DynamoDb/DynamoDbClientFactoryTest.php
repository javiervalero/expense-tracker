<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\DynamoDb;

use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use Aws\CommandInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;

final class DynamoDbClientFactoryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        // The factory passes no credentials, so the default chain runs: give it environment ones, so that a test
        // never waits on the instance-metadata endpoint.
        foreach (['AWS_ACCESS_KEY_ID' => 'localdev', 'AWS_SECRET_ACCESS_KEY' => 'localdevsecret', 'AWS_EC2_METADATA_DISABLED' => 'true'] as $name => $value) {
            $this->savedEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testClientUsesShortTimeouts(): void
    {
        /** @var array{connect_timeout?: int, timeout?: int} $options */
        $options = [];
        $handler = new MockHandler([static function (CommandInterface $command) use (&$options): Result {
            $http = $command['@http'];
            self::assertIsArray($http);
            $options = $http;

            return new Result([]);
        }]);
        $client = DynamoDbClientFactory::create(
            DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'AWS_REGION' => 'eu-west-1']),
            $handler,
        );
        $client->listTables();

        self::assertSame(1, $options['connect_timeout'] ?? null);
        self::assertSame(3, $options['timeout'] ?? null);
    }

    public function testAThrottledCallIsAttemptedThreeTimesInTotal(): void
    {
        $attempts = 0;
        $throttled = static function (CommandInterface $command) use (&$attempts): never {
            ++$attempts;

            throw new DynamoDbException('slow down', $command, ['code' => 'ProvisionedThroughputExceededException']);
        };
        $client = DynamoDbClientFactory::create(
            DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'AWS_REGION' => 'eu-west-1']),
            new MockHandler([$throttled, $throttled, $throttled, $throttled, $throttled]),
        );

        try {
            $client->listTables();
            self::fail('Expected the throttling error after the retries');
        } catch (DynamoDbException $e) {
            self::assertSame('ProvisionedThroughputExceededException', $e->getAwsErrorCode());
        }

        self::assertSame(3, $attempts);
    }

    public function testRegionAndEndpointComeFromTheSettings(): void
    {
        $client = DynamoDbClientFactory::create(DynamoDbSettings::fromEnvironment([
            'DYNAMODB_TABLE' => 'tbl',
            'DYNAMODB_ENDPOINT' => 'http://dynamodb:8000',
            'AWS_REGION' => 'eu-west-1',
        ]));

        self::assertSame('eu-west-1', $client->getRegion());
        self::assertSame('http://dynamodb:8000', (string) $client->getEndpoint());
    }

    public function testWithoutAnEndpointTheRegionalAwsEndpointIsUsed(): void
    {
        $client = DynamoDbClientFactory::create(DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'AWS_REGION' => 'eu-west-1']));

        self::assertSame('dynamodb.eu-west-1.amazonaws.com', $client->getEndpoint()->getHost());
    }

    public function testAnEndpointFromTheEnvironmentNeverMovesTheClient(): void
    {
        $saved = [getenv('AWS_ENDPOINT_URL'), getenv('AWS_ENDPOINT_URL_DYNAMODB')];
        putenv('AWS_ENDPOINT_URL=https://evil.example');
        putenv('AWS_ENDPOINT_URL_DYNAMODB=https://evil-db.example');
        try {
            $client = DynamoDbClientFactory::create(DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'AWS_REGION' => 'eu-west-1']));

            self::assertSame('https://dynamodb.eu-west-1.amazonaws.com', (string) $client->getEndpoint());
        } finally {
            putenv($saved[0] === false ? 'AWS_ENDPOINT_URL' : 'AWS_ENDPOINT_URL=' . $saved[0]);
            putenv($saved[1] === false ? 'AWS_ENDPOINT_URL_DYNAMODB' : 'AWS_ENDPOINT_URL_DYNAMODB=' . $saved[1]);
        }
    }
}
