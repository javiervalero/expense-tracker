<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\AwsClient\Leaky;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Sdk as Factory;

final class Leaky
{
    public function run(): array
    {
        return [
            new DynamoDbClient(['region' => 'eu-west-1', 'credentials' => ['key' => 'a', 'secret' => 'b']]),
            new \Aws\Sdk([]),
            new  sdk([]),
            Factory::class,
        ];
    }
}
