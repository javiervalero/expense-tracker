<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ClientFactory\Leaky;

use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory as Factory;

final class Leaky
{
    public function run(object $settings): array
    {
        return [
            DynamoDbClientFactory::create($settings),
            \App\Infrastructure\DynamoDb\DynamoDbClientFactory::CREATE($settings),
            dynamodbclientfactory::create($settings),
        ];
    }
}
