<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\DynamoDb;

use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use PHPUnit\Framework\TestCase;

final class DynamoDbClientFactoryTest extends TestCase
{
    public function testClientUsesShortTimeoutsAndStandardRetries(): void
    {
        $client = DynamoDbClientFactory::create(DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'AWS_REGION' => 'eu-west-1']));

        $http = $client->getConfig('http');
        self::assertIsArray($http);
        self::assertSame(1, $http['connect_timeout']);
        self::assertSame(3, $http['timeout']);
        $retries = $client->getConfig('retries');
        self::assertIsObject($retries);
        self::assertSame('standard', $retries->getMode());
        self::assertSame(3, $retries->getMaxAttempts());
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
}
