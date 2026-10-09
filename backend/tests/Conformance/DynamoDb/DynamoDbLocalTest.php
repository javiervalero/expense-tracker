<?php

declare(strict_types=1);

namespace App\Tests\Conformance\DynamoDb;

use App\Tests\Support\LocalAws;
use Aws\DynamoDb\DynamoDbClient;
use PHPUnit\Framework\TestCase;

/**
 * What the data model (ADR 0002) relies on from DynamoDB, checked against DynamoDB Local: a table with the
 * local secondary index LSI1 (PK + LSI1SK), a strongly consistent `LSI1SK BETWEEN` query, and a sparse index.
 * TTL is deliberately not tested: DynamoDB Local does not expire items, so nothing may depend on it.
 */
final class DynamoDbLocalTest extends TestCase
{
    private const USER = 'USER#conformance';

    private DynamoDbClient $dynamoDb;
    private string $table;

    protected function setUp(): void
    {
        $this->dynamoDb = LocalAws::fromGlobals()->dynamoDb();
        $this->table = 'conformance-' . bin2hex(random_bytes(6));

        $this->dynamoDb->createTable([
            'TableName' => $this->table,
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [
                ['AttributeName' => 'PK', 'AttributeType' => 'S'],
                ['AttributeName' => 'SK', 'AttributeType' => 'S'],
                ['AttributeName' => 'LSI1SK', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [
                ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                ['AttributeName' => 'SK', 'KeyType' => 'RANGE'],
            ],
            'LocalSecondaryIndexes' => [[
                'IndexName' => 'LSI1',
                'KeySchema' => [
                    ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                    ['AttributeName' => 'LSI1SK', 'KeyType' => 'RANGE'],
                ],
                'Projection' => ['ProjectionType' => 'ALL'],
            ]],
        ]);
        $this->dynamoDb->waitUntil('TableExists', ['TableName' => $this->table]);
    }

    protected function tearDown(): void
    {
        $this->dynamoDb->deleteTable(['TableName' => $this->table]);
    }

    public function testStronglyConsistentLsiQueryReturnsTheDateRangeNewestFirst(): void
    {
        $this->putExpense('a', 'EXP#2026-01-31#a');
        $this->putExpense('b', 'EXP#2026-02-01#b');
        $this->putExpense('c', 'EXP#2026-02-15#c');
        $this->putExpense('d', 'EXP#2026-02-28#d');
        $this->putExpense('e', 'EXP#2026-03-01#e');

        $result = $this->dynamoDb->query([
            'TableName' => $this->table,
            'IndexName' => 'LSI1',
            'ConsistentRead' => true,
            'ScanIndexForward' => false,
            'KeyConditionExpression' => 'PK = :pk AND LSI1SK BETWEEN :from AND :to',
            'ExpressionAttributeValues' => [
                ':pk' => ['S' => self::USER],
                ':from' => ['S' => 'EXP#2026-02-01'],
                ':to' => ['S' => 'EXP#2026-02-28~'],
            ],
        ]);

        self::assertSame(['d', 'c', 'b'], $this->sortKeyIds($result->get('Items')));
    }

    public function testIndexIsSparseAndOnlyHoldsItemsThatHaveLsi1Sk(): void
    {
        $this->putExpense('a', 'EXP#2026-02-01#a');
        $this->dynamoDb->putItem([
            'TableName' => $this->table,
            'Item' => ['PK' => ['S' => self::USER], 'SK' => ['S' => 'PROFILE']],
        ]);

        $result = $this->dynamoDb->query([
            'TableName' => $this->table,
            'IndexName' => 'LSI1',
            'ConsistentRead' => true,
            'KeyConditionExpression' => 'PK = :pk',
            'ExpressionAttributeValues' => [':pk' => ['S' => self::USER]],
        ]);

        self::assertSame(['a'], $this->sortKeyIds($result->get('Items')));
    }

    public function testEmptyRangeReturnsNoItems(): void
    {
        $this->putExpense('a', 'EXP#2026-02-01#a');

        $result = $this->dynamoDb->query([
            'TableName' => $this->table,
            'IndexName' => 'LSI1',
            'ConsistentRead' => true,
            'KeyConditionExpression' => 'PK = :pk AND LSI1SK BETWEEN :from AND :to',
            'ExpressionAttributeValues' => [
                ':pk' => ['S' => self::USER],
                ':from' => ['S' => 'EXP#2030-01-01'],
                ':to' => ['S' => 'EXP#2030-12-31~'],
            ],
        ]);

        self::assertSame(0, $result->get('Count'));
    }

    private function putExpense(string $id, string $dateKey): void
    {
        $this->dynamoDb->putItem([
            'TableName' => $this->table,
            'Item' => [
                'PK' => ['S' => self::USER],
                'SK' => ['S' => 'EXP#' . $id],
                'LSI1SK' => ['S' => $dateKey],
            ],
        ]);
    }

    /**
     * @return list<string> the expense ids of the returned items, in the returned order
     */
    private function sortKeyIds(mixed $items): array
    {
        self::assertIsArray($items);

        $ids = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $sortKeyAttribute = $item['SK'] ?? null;
            self::assertIsArray($sortKeyAttribute);
            $sortKey = $sortKeyAttribute['S'] ?? null;
            self::assertIsString($sortKey);
            $ids[] = substr($sortKey, strlen('EXP#'));
        }

        return $ids;
    }
}
