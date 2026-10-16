<?php

declare(strict_types=1);

namespace App\Tests\Conformance\DynamoDb;

use App\Tests\Support\LocalAws;
use PHPUnit\Framework\TestCase;

/**
 * The table the local bootstrap created (`DYNAMODB_TABLE`) is the one infra/dynamodb/table.json describes: keys, the
 * LSI1 index with its projection, and the billing mode. That file is also what Terraform reads in Phase 7, so this
 * is what keeps the local table and the AWS table from drifting apart.
 */
final class AppTableMatchesSchemaTest extends TestCase
{
    private const SCHEMA_FILE = __DIR__ . '/../../../../infra/dynamodb/table.json';

    /** @var array<string, mixed> */
    private array $schema;
    /** @var array<string, mixed> */
    private array $table;

    protected function setUp(): void
    {
        $tableName = getenv('DYNAMODB_TABLE');
        self::assertIsString($tableName, 'DYNAMODB_TABLE is not set (see .env.example)');
        self::assertNotSame('', $tableName, 'DYNAMODB_TABLE is empty (see .env.example)');

        $schema = json_decode((string) file_get_contents(self::SCHEMA_FILE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */
        $this->schema = $schema;

        $table = LocalAws::fromGlobals()->dynamoDb()->describeTable(['TableName' => $tableName])->get('Table');
        self::assertIsArray($table);
        /** @var array<string, mixed> $table */
        $this->table = $table;
    }

    public function testPrimaryKeyIsPkAndSk(): void
    {
        self::assertSame(
            [['AttributeName' => 'PK', 'KeyType' => 'HASH'], ['AttributeName' => 'SK', 'KeyType' => 'RANGE']],
            $this->table['KeySchema'],
        );
        self::assertSame($this->schema['KeySchema'], $this->table['KeySchema']);
    }

    public function testAttributeDefinitionsMatchTheSchemaFile(): void
    {
        self::assertSame(
            self::attributeTypesByName($this->schema['AttributeDefinitions']),
            self::attributeTypesByName($this->table['AttributeDefinitions']),
        );
    }

    public function testLsi1IsPkPlusLsi1SkAndProjectsAllAttributes(): void
    {
        $indexes = $this->table['LocalSecondaryIndexes'] ?? null;
        self::assertIsArray($indexes);
        self::assertCount(1, $indexes);
        $index = $indexes[0];
        self::assertIsArray($index);

        self::assertSame('LSI1', $index['IndexName']);
        self::assertSame(
            [['AttributeName' => 'PK', 'KeyType' => 'HASH'], ['AttributeName' => 'LSI1SK', 'KeyType' => 'RANGE']],
            $index['KeySchema'],
        );
        self::assertSame(['ProjectionType' => 'ALL'], $index['Projection']);
        // The schema file says the same, field for field (DynamoDB adds sizes and ARNs, which are not compared).
        self::assertSame(
            $this->schema['LocalSecondaryIndexes'],
            [['IndexName' => $index['IndexName'], 'KeySchema' => $index['KeySchema'], 'Projection' => $index['Projection']]],
        );
    }

    public function testBillingIsPayPerRequest(): void
    {
        $summary = $this->table['BillingModeSummary'] ?? null;
        self::assertIsArray($summary);

        self::assertSame('PAY_PER_REQUEST', $summary['BillingMode']);
        self::assertSame($this->schema['BillingMode'], $summary['BillingMode']);
    }

    /**
     * @return array<string, string> attribute name => type, sorted by name
     */
    private static function attributeTypesByName(mixed $definitions): array
    {
        self::assertIsArray($definitions);
        $types = [];
        foreach ($definitions as $definition) {
            self::assertIsArray($definition);
            $name = $definition['AttributeName'] ?? null;
            $type = $definition['AttributeType'] ?? null;
            self::assertIsString($name);
            self::assertIsString($type);
            $types[$name] = $type;
        }
        ksort($types);

        return $types;
    }
}
