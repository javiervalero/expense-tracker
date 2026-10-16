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
 * {@see ApiServices} wired to the SDK's MockHandler instead of a network: the handler answers every request, so
 * nothing is sent. Only construction is covered. Calling the store would still need credentials, which the SDK
 * looks up in its default chain (the environment, then the instance metadata service), and a unit-test container
 * has none; a test that calls the store must run where the chain resolves them, or hand the store its own client.
 * The clock is frozen.
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
