<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\UserPartition\Leaky;

final class Leaky
{
    public function run(object $client, ?object $maybe, string $condition, string $userId): void
    {
        $client->query(['KeyConditionExpression' => 'PK = :pk']);
        $maybe?->query(['KeyConditionExpression' => $condition]);
        $client->query([
            'TableName' => 'T',
            'KeyConditionExpression' => 'PK = :pk AND SK > :x',
        ]);
        $client->query();
        $key = 'USER#' . $userId;
        $other = "USER#{$userId}";
        $third = <<<TXT
            USER#{$userId}
            TXT;
        $fine = 'USER-' . $userId;
        $client->query([
            'KeyConditionExpression' => 'PK = :pk',
            'FilterExpression' => 'SK BETWEEN :a AND :b',
        ]);
        $client->query(['KeyConditionExpression' => 'PK = :pk AND begins_with(PK, :prefix)']);
        $client->query(['KeyConditionExpression' => 'PK = :pk', 'Message' => 'between two dates']);
        $client->query('PK = :pk', [], [], null, true, 10);
        $client->query($condition, [], [], null, true, 10);
        $client->query(['Message' => 'begins_with(SK, x)', 'KeyConditionExpression' => 'PK = :pk']);
        $hex = "USER\x23" . $userId;
        $octal = "USER\043{$userId}";
        $unicode = "USER\u{23}";
        $single = 'USER\x23 stays text';
    }
}
