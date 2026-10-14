<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Bootstrap;

use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use LocalAws\Bootstrap\DynamoDbBootstrapper;
use LocalAws\Bootstrap\TableDiffers;
use LocalAws\Bootstrap\TableSchema;
use LocalAws\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;

final class DynamoDbBootstrapperTest extends TestCase
{
    private const TABLE = 'expense-tracker-local';
    private const SCHEMA_FILE = __DIR__ . '/../../../../../infra/dynamodb/table.json';

    private MockHandler $mock;
    /** @var list<string> command names in the order the SDK sent them */
    private array $calls = [];
    /** @var array<string, array<array-key, mixed>> command name => parameters of the last such command */
    private array $lastParams = [];
    /** @var list<array<string, mixed>> */
    private array $logged = [];

    protected function tearDown(): void
    {
        // A response nobody asked for means the bootstrapper sent fewer commands than the test expects.
        if (isset($this->mock)) {
            self::assertCount(0, $this->mock, 'The mock handler still holds responses that the bootstrapper never asked for');
        }
    }

    private function dynamoDb(): DynamoDbClient
    {
        $this->mock = new MockHandler();
        $this->calls = [];
        $this->lastParams = [];
        $this->logged = [];

        $client = new DynamoDbClient([
            'version' => '2012-08-10',
            'region' => 'eu-west-1',
            'endpoint' => 'http://dynamodb:8000',
            'retries' => 0,
            'credentials' => ['key' => 'localdev', 'secret' => 'localdevsecret'],
            'handler' => $this->mock,
        ]);
        $client->getHandlerList()->appendSign(\Aws\Middleware::tap(function (CommandInterface $command): void {
            $this->calls[] = $command->getName();
            $this->lastParams[$command->getName()] = $command->toArray();
        }));

        return $client;
    }

    private function bootstrapper(DynamoDbClient $client): DynamoDbBootstrapper
    {
        return new DynamoDbBootstrapper(
            $client,
            new JsonLogger(
                function (string $line): void {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    self::assertIsArray($decoded);
                    /** @var array<string, mixed> $decoded */
                    $this->logged[] = $decoded;
                },
                static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-10-14T12:00:00+00:00'),
            ),
            TableSchema::fromFile(self::SCHEMA_FILE),
            self::TABLE,
        );
    }

    private static function error(string $operation, string $code): DynamoDbException
    {
        return new DynamoDbException($code, new \Aws\Command($operation), ['code' => $code]);
    }

    /**
     * @return array{Table: array<string, mixed>} what DescribeTable returns for the table we want (as DynamoDB Local does,
     *         with its extra fields and a different order)
     */
    private static function describeResult(): array
    {
        return ['Table' => [
            'AttributeDefinitions' => [
                ['AttributeName' => 'LSI1SK', 'AttributeType' => 'S'],
                ['AttributeName' => 'PK', 'AttributeType' => 'S'],
                ['AttributeName' => 'SK', 'AttributeType' => 'S'],
            ],
            'TableName' => self::TABLE,
            'KeySchema' => [
                ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                ['AttributeName' => 'SK', 'KeyType' => 'RANGE'],
            ],
            'TableStatus' => 'ACTIVE',
            'ProvisionedThroughput' => ['ReadCapacityUnits' => 0, 'WriteCapacityUnits' => 0],
            'BillingModeSummary' => ['BillingMode' => 'PAY_PER_REQUEST'],
            'LocalSecondaryIndexes' => [[
                'IndexName' => 'LSI1',
                'KeySchema' => [
                    ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                    ['AttributeName' => 'LSI1SK', 'KeyType' => 'RANGE'],
                ],
                'Projection' => ['ProjectionType' => 'ALL'],
                'IndexSizeBytes' => 0,
            ]],
        ]];
    }

    /**
     * @return array{TimeToLiveDescription: array<string, string>} what DescribeTimeToLive returns
     */
    private static function ttlResult(string $status, ?string $attribute = null): array
    {
        return ['TimeToLiveDescription' => ['TimeToLiveStatus' => $status] + ($attribute === null ? [] : ['AttributeName' => $attribute])];
    }

    /**
     * @param array<string, mixed> $projection
     *
     * @return array<string, mixed> a local secondary index on PK + $rangeKey
     */
    private static function localIndex(string $name, string $rangeKey, array $projection): array
    {
        return [
            'IndexName' => $name,
            'KeySchema' => [
                ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                ['AttributeName' => $rangeKey, 'KeyType' => 'RANGE'],
            ],
            'Projection' => $projection,
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     *
     * @return array{Table: array<string, mixed>}
     */
    private static function describeResultWith(callable $change): array
    {
        $result = self::describeResult();
        /** @var array{Table: array<string, mixed>} */
        return ['Table' => $change($result['Table'])];
    }

    /**
     * @param list<array<string, mixed>|\Throwable> $responses
     */
    private function queue(array $responses): void
    {
        foreach ($responses as $response) {
            $this->mock->append($response instanceof \Throwable ? $response : new Result($response));
        }
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(static fn(array $entry): string => is_string($entry['message']) ? $entry['message'] : '', $this->logged);
    }

    public function testCreatesAMissingTableWaitsForItAndEnablesTtl(): void
    {
        $client = $this->dynamoDb();
        $this->queue([
            self::error('DescribeTable', 'ResourceNotFoundException'),
            [],
            self::describeResult(),
            self::ttlResult('DISABLED'),
            [],
        ]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(2, $changes);
        self::assertSame(['DescribeTable', 'CreateTable', 'DescribeTable', 'DescribeTimeToLive', 'UpdateTimeToLive'], $this->calls);
        self::assertSame(['created table', 'enabled TTL', 'bootstrap finished'], $this->messages());
    }

    public function testCreatesTheTableFromTheSchemaFileUnderTheConfiguredName(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::error('DescribeTable', 'ResourceNotFoundException'), [], self::describeResult(), self::ttlResult('DISABLED'), []]);

        $this->bootstrapper($client)->run();

        $create = $this->lastParams['CreateTable'];
        self::assertSame(self::TABLE, $create['TableName']);
        self::assertSame('PAY_PER_REQUEST', $create['BillingMode']);
        self::assertSame([[
            'IndexName' => 'LSI1',
            'KeySchema' => [
                ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                ['AttributeName' => 'LSI1SK', 'KeyType' => 'RANGE'],
            ],
            'Projection' => ['ProjectionType' => 'ALL'],
        ]], $create['LocalSecondaryIndexes']);
        self::assertSame(
            [self::TABLE, ['Enabled' => true, 'AttributeName' => 'expiresAt']],
            [$this->lastParams['UpdateTimeToLive']['TableName'] ?? null, $this->lastParams['UpdateTimeToLive']['TimeToLiveSpecification'] ?? null],
        );
    }

    public function testOnlyDescribesAnExistingTableThatMatches(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResult(), self::ttlResult('ENABLED', 'expiresAt')]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(0, $changes);
        self::assertSame(['DescribeTable', 'DescribeTimeToLive'], $this->calls);
        self::assertSame(['no changes'], $this->messages());
    }

    public function testEnablesTtlOnAnExistingTableWhereItIsDisabled(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResult(), self::ttlResult('DISABLED'), []]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(1, $changes);
        self::assertSame(['DescribeTable', 'DescribeTimeToLive', 'UpdateTimeToLive'], $this->calls);
        self::assertSame(['enabled TTL', 'bootstrap finished'], $this->messages());
        self::assertSame(['Enabled' => true, 'AttributeName' => 'expiresAt'], $this->lastParams['UpdateTimeToLive']['TimeToLiveSpecification'] ?? null);
    }

    public function testEnablesTtlWhenTheTableHasNoTtlAttributeAtAll(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResult(), ['TimeToLiveDescription' => []], []]);

        self::assertSame(1, $this->bootstrapper($client)->run());
        self::assertContains('UpdateTimeToLive', $this->calls);
    }

    public function testLeavesTtlAloneWhileItIsBeingEnabled(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResult(), self::ttlResult('ENABLING', 'expiresAt')]);

        self::assertSame(0, $this->bootstrapper($client)->run());
        self::assertNotContains('UpdateTimeToLive', $this->calls);
    }

    public function testFailsWhenTtlIsEnabledOnAnotherAttribute(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResult(), self::ttlResult('ENABLED', 'ttl')]);

        try {
            $this->bootstrapper($client)->run();
            self::fail('TTL on another attribute cannot be switched in place, so the bootstrap must fail');
        } catch (TableDiffers $e) {
            self::assertStringContainsString('TimeToLive', $e->getMessage());
            self::assertStringContainsString('ttl', $e->getMessage());
            self::assertStringContainsString('make down && make up', $e->getMessage());
        }

        self::assertNotContains('UpdateTimeToLive', $this->calls);
    }

    public function testRetriesTtlOnTheNextRunAfterItFailedOnce(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResult(), self::ttlResult('DISABLED'), self::error('UpdateTimeToLive', 'InternalServerError')]);
        try {
            $this->bootstrapper($client)->run();
            self::fail('The first run must fail on the TTL error');
        } catch (DynamoDbException) {
            // expected
        }

        $this->queue([self::describeResult(), self::ttlResult('DISABLED'), []]);
        $changes = $this->bootstrapper($client)->run();

        self::assertSame(1, $changes);
        self::assertSame('UpdateTimeToLive', end($this->calls));
    }

    public function testLogsAndContinuesWhenDescribeTimeToLiveIsNotSupported(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResult(), self::error('DescribeTimeToLive', 'UnsupportedOperationException')]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(0, $changes);
        self::assertNotContains('UpdateTimeToLive', $this->calls);
        self::assertSame(['TTL not supported locally', 'no changes'], $this->messages());
    }

    public function testStillFailsOnAnyOtherErrorWhileDescribingTtl(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResult(), self::error('DescribeTimeToLive', 'AccessDeniedException')]);

        $this->expectException(DynamoDbException::class);

        $this->bootstrapper($client)->run();
    }

    public function testFailsWithTheRemedyAndNeverDeletesWhenTheTableDiffers(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static fn(array $table): array => ['LocalSecondaryIndexes' => []] + $table)]);

        try {
            $this->bootstrapper($client)->run();
            self::fail('A table that differs from the schema must fail the bootstrap');
        } catch (TableDiffers $e) {
            self::assertStringContainsString('make down && make up', $e->getMessage());
            self::assertStringContainsString(self::TABLE, $e->getMessage());
            self::assertStringContainsString('LocalSecondaryIndexes', $e->getMessage());
        }

        self::assertSame(['DescribeTable'], $this->calls);
        self::assertNotContains('DeleteTable', $this->calls);
        self::assertNotContains('CreateTable', $this->calls);
    }

    public function testDetectsADifferentBillingMode(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static fn(array $table): array => ['BillingModeSummary' => ['BillingMode' => 'PROVISIONED']] + $table)]);

        $this->expectException(TableDiffers::class);
        $this->expectExceptionMessage('BillingMode');

        $this->bootstrapper($client)->run();
    }

    public function testTreatsAMissingBillingModeSummaryAsProvisioned(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static function (array $table): array {
            unset($table['BillingModeSummary']);

            return $table;
        })]);

        $this->expectException(TableDiffers::class);

        $this->bootstrapper($client)->run();
    }

    public function testDetectsADifferentProjection(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static function (array $table): array {
            $table['LocalSecondaryIndexes'] = [[
                'IndexName' => 'LSI1',
                'KeySchema' => [
                    ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                    ['AttributeName' => 'LSI1SK', 'KeyType' => 'RANGE'],
                ],
                'Projection' => ['ProjectionType' => 'KEYS_ONLY'],
            ]];

            return $table;
        })]);

        $this->expectException(TableDiffers::class);
        $this->expectExceptionMessage('LocalSecondaryIndexes');

        $this->bootstrapper($client)->run();
    }

    public function testDetectsADifferentKeySchema(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static function (array $table): array {
            $table['KeySchema'] = [['AttributeName' => 'PK', 'KeyType' => 'HASH']];

            return $table;
        })]);

        $this->expectException(TableDiffers::class);
        $this->expectExceptionMessage('KeySchema');

        $this->bootstrapper($client)->run();
    }

    public function testDetectsADifferentAttributeType(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static function (array $table): array {
            $table['AttributeDefinitions'] = [
                ['AttributeName' => 'LSI1SK', 'AttributeType' => 'S'],
                ['AttributeName' => 'PK', 'AttributeType' => 'S'],
                ['AttributeName' => 'SK', 'AttributeType' => 'N'],
            ];

            return $table;
        })]);

        $this->expectException(TableDiffers::class);
        $this->expectExceptionMessage('AttributeDefinitions');

        $this->bootstrapper($client)->run();
    }

    public function testLogsAndContinuesWhenTheEmulatorDoesNotSupportTtl(): void
    {
        $client = $this->dynamoDb();
        $this->queue([
            self::error('DescribeTable', 'ResourceNotFoundException'),
            [],
            self::describeResult(),
            self::ttlResult('DISABLED'),
            self::error('UpdateTimeToLive', 'UnknownOperationException'),
        ]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(1, $changes);
        self::assertSame(['created table', 'TTL not supported locally', 'bootstrap finished'], $this->messages());
    }

    public function testStillFailsOnAnyOtherErrorWhileEnablingTtl(): void
    {
        $client = $this->dynamoDb();
        $this->queue([
            self::error('DescribeTable', 'ResourceNotFoundException'),
            [],
            self::describeResult(),
            self::ttlResult('DISABLED'),
            self::error('UpdateTimeToLive', 'InternalServerError'),
        ]);

        $this->expectException(DynamoDbException::class);

        $this->bootstrapper($client)->run();
    }

    public function testFailsOnAnUnexpectedDescribeError(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::error('DescribeTable', 'AccessDeniedException')]);

        try {
            $this->bootstrapper($client)->run();
            self::fail('An unexpected DescribeTable error must not be treated as a missing table');
        } catch (DynamoDbException) {
            self::assertSame(['DescribeTable'], $this->calls);
        }
    }

    public function testToleratesAConcurrentCreationAndStillWaitsAndEnsuresTtl(): void
    {
        $client = $this->dynamoDb();
        $this->queue([
            self::error('DescribeTable', 'ResourceNotFoundException'),
            self::error('CreateTable', 'ResourceInUseException'),
            self::describeResult(),
            self::describeResult(),
            self::ttlResult('DISABLED'),
            [],
        ]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(1, $changes);
        self::assertSame(['DescribeTable', 'CreateTable', 'DescribeTable', 'DescribeTable', 'DescribeTimeToLive', 'UpdateTimeToLive'], $this->calls);
        self::assertSame(['enabled TTL', 'bootstrap finished'], $this->messages());
    }

    public function testAConcurrentCreationOfADifferentTableFails(): void
    {
        $client = $this->dynamoDb();
        $this->queue([
            self::error('DescribeTable', 'ResourceNotFoundException'),
            self::error('CreateTable', 'ResourceInUseException'),
            self::describeResultWith(static fn(array $table): array => ['LocalSecondaryIndexes' => []] + $table),
        ]);

        $this->expectException(TableDiffers::class);

        $this->bootstrapper($client)->run();
    }

    public function testDetectsAnExtraGlobalSecondaryIndex(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static function (array $table): array {
            $table['GlobalSecondaryIndexes'] = [[
                'IndexName' => 'GSI1',
                'KeySchema' => [
                    ['AttributeName' => 'SK', 'KeyType' => 'HASH'],
                    ['AttributeName' => 'PK', 'KeyType' => 'RANGE'],
                ],
                'Projection' => ['ProjectionType' => 'ALL'],
            ]];

            return $table;
        })]);

        $this->expectException(TableDiffers::class);
        $this->expectExceptionMessage('GlobalSecondaryIndexes');

        $this->bootstrapper($client)->run();
    }

    public function testDetectsAnExtraLocalSecondaryIndex(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static function (array $table): array {
            $table['LocalSecondaryIndexes'] = [
                self::localIndex('LSI1', 'LSI1SK', ['ProjectionType' => 'ALL']),
                self::localIndex('LSI2', 'SK', ['ProjectionType' => 'ALL']),
            ];

            return $table;
        })]);

        $this->expectException(TableDiffers::class);
        $this->expectExceptionMessage('LocalSecondaryIndexes');

        $this->bootstrapper($client)->run();
    }

    public function testDetectsIncludeProjectionInsteadOfAll(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static function (array $table): array {
            $table['LocalSecondaryIndexes'] = [self::localIndex('LSI1', 'LSI1SK', ['ProjectionType' => 'INCLUDE', 'NonKeyAttributes' => ['amount']])];

            return $table;
        })]);

        $this->expectException(TableDiffers::class);
        $this->expectExceptionMessage('LocalSecondaryIndexes');

        $this->bootstrapper($client)->run();
    }

    public function testDetectsExtraNonKeyAttributesOnTheIndexProjection(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static function (array $table): array {
            $table['LocalSecondaryIndexes'] = [self::localIndex('LSI1', 'LSI1SK', ['ProjectionType' => 'ALL', 'NonKeyAttributes' => ['amount']])];

            return $table;
        })]);

        $this->expectException(TableDiffers::class);
        $this->expectExceptionMessage('LocalSecondaryIndexes');

        $this->bootstrapper($client)->run();
    }

    public function testDetectsAnExtraAttributeDefinition(): void
    {
        $client = $this->dynamoDb();
        $this->queue([self::describeResultWith(static function (array $table): array {
            $table['AttributeDefinitions'] = [
                ['AttributeName' => 'LSI1SK', 'AttributeType' => 'S'],
                ['AttributeName' => 'PK', 'AttributeType' => 'S'],
                ['AttributeName' => 'SK', 'AttributeType' => 'S'],
                ['AttributeName' => 'extra', 'AttributeType' => 'S'],
            ];

            return $table;
        })]);

        $this->expectException(TableDiffers::class);
        $this->expectExceptionMessage('AttributeDefinitions');

        $this->bootstrapper($client)->run();
    }
}
