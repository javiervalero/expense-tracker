<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Bootstrap;

use LocalAws\Bootstrap\TableSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableSchemaTest extends TestCase
{
    private const REAL_FILE = __DIR__ . '/../../../../../infra/dynamodb/table.json';

    /**
     * @return array<string, mixed>
     */
    private static function validDefinition(): array
    {
        return [
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
            'BillingMode' => 'PAY_PER_REQUEST',
            'TimeToLiveAttribute' => 'expiresAt',
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function fromDefinition(array $definition): TableSchema
    {
        return TableSchema::fromJson(json_encode($definition, JSON_THROW_ON_ERROR));
    }

    public function testLoadsTheRepositoryTableDefinition(): void
    {
        $schema = TableSchema::fromFile(self::REAL_FILE);

        $params = $schema->createTableParameters('expense-tracker-local');
        self::assertSame('expense-tracker-local', $params['TableName']);
        self::assertSame('PAY_PER_REQUEST', $params['BillingMode']);
        self::assertSame('expiresAt', $schema->timeToLiveAttribute);
        self::assertSame(self::validDefinition()['LocalSecondaryIndexes'], $params['LocalSecondaryIndexes']);
    }

    public function testCreateParametersCarryTheTableNameAndNotTheTtlAttribute(): void
    {
        $params = self::fromDefinition(self::validDefinition())->createTableParameters('my-table');

        self::assertSame('my-table', $params['TableName']);
        self::assertArrayNotHasKey('TimeToLiveAttribute', $params);
        self::assertSame(self::validDefinition()['AttributeDefinitions'], $params['AttributeDefinitions']);
        self::assertSame(self::validDefinition()['KeySchema'], $params['KeySchema']);
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        TableSchema::fromJson('{ "KeySchema": ');
    }

    public function testRejectsJsonThatIsNotAnObject(): void
    {
        $this->expectException(\RuntimeException::class);

        TableSchema::fromJson('"just a string"');
    }

    public function testRejectsAMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be read');

        TableSchema::fromFile(__DIR__ . '/does-not-exist.json');
    }

    #[DataProvider('requiredFields')]
    public function testRejectsADefinitionWithAMissingField(string $field): void
    {
        $definition = self::validDefinition();
        unset($definition[$field]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($field);

        self::fromDefinition($definition);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredFields(): iterable
    {
        foreach (['AttributeDefinitions', 'KeySchema', 'LocalSecondaryIndexes', 'BillingMode', 'TimeToLiveAttribute'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('wrongTypes')]
    public function testRejectsAFieldOfTheWrongType(string $field, mixed $value): void
    {
        $definition = [$field => $value] + self::validDefinition();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($field);

        self::fromDefinition($definition);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'empty key schema' => ['KeySchema', []];
        yield 'attribute definitions not a list' => ['AttributeDefinitions', 'PK'];
        yield 'billing mode not a string' => ['BillingMode', 5];
        yield 'ttl attribute empty' => ['TimeToLiveAttribute', ''];
    }

    public function testRejectsADefinitionThatCarriesATableName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TableName');

        self::fromDefinition(['TableName' => 'hardcoded'] + self::validDefinition());
    }
}
