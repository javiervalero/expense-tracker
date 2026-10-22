<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ClientFactory\Clean;

use App\Infrastructure\DynamoDb\DynamoDbClientFactory;

/**
 * Prose may say DynamoDbClientFactory::create($settings) without calling it.
 */
final class Clean
{
    public function run(object $other): array
    {
        $text = 'DynamoDbClientFactory::create($settings) in a string is fine';
        // DynamoDbClientFactory::create($settings) in a comment is fine too.

        return [$other::create(), DynamoDbClientFactory::class, \App\Other\Factory::create(), $text];
    }
}
