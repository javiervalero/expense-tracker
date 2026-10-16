<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Api\ApiServices;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Id\RandomUuidGenerator;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\FrozenClock;
use Aws\MockHandler;

/**
 * {@see ApiServices} that never touch a network: the DynamoDB client is the SDK's MockHandler (which, when its queue
 * is empty, fails the test instead of sending anything), the clock is frozen.
 */
final class OfflineServices
{
    /**
     * @param resource|null $logStream
     */
    public static function create(?MockHandler $dynamoDb = null, mixed $logStream = null): ApiServices
    {
        $settings = DynamoDbSettings::fromEnvironment([
            'DYNAMODB_TABLE' => 'expense-tracker-test',
            'AWS_REGION' => 'eu-west-1',
        ]);
        $client = DynamoDbClientFactory::create($settings, $dynamoDb ?? new MockHandler());

        return new ApiServices(
            new ItemStore($client, $settings->tableName),
            new FrozenClock(),
            new RandomUuidGenerator(),
            new JsonLogger($logStream ?? self::memory()),
        );
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('php://memory is unavailable.');
        }

        return $stream;
    }
}
