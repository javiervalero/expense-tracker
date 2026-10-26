<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\UserPartition\Clean;

final class Clean
{
    public function run(object $client, ?object $maybe): void
    {
        $client->query([
            'KeyConditionExpression' => 'PK = :pk AND begins_with(SK, :prefix)',
        ]);
        $maybe?->query(['KeyConditionExpression' => 'PK = :pk AND LSI1SK BETWEEN :a AND :b', 'Limit' => 5]);
        $client->query([
            'KeyConditionExpression' => "PK = :pk AND begins_with(SK, :prefix)",
            'ExpressionAttributeValues' => [':note' => 'USER-ids are not keys', ':q' => $client->query(['KeyConditionExpression' => 'x AND SK BETWEEN :a AND :b'])],
        ]);
        $client->query([
            'FilterExpression' => 'amount > :min',
            'KeyConditionExpression' => 'PK = :pk AND SK BETWEEN :a AND :b',
        ]);
        $client->query('#pk = :pk AND begins_with(SK, :prefix)', ['#pk' => 'PK'], [':pk' => 'x'], null, true, 10);
        $client->queryString = 'query( is only text here';
    }

    public function query(string $notACall): string
    {
        return $notACall;
    }
}
