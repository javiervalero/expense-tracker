<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\AwsClient\Clean;

use Aws\DynamoDb\DynamoDbClient;

/**
 * Prose may say new DynamoDbClient(['credentials' => []]) without building one.
 */
final class Clean
{
    public function run(DynamoDbClient $client): string
    {
        $text = "new Sdk() and 'credentials' inside a longer string is fine";
        // new DynamoDbClient(['credentials' => []]) in a comment is fine too.

        return $client::class . $text . (new \stdClass())::class;
    }
}
