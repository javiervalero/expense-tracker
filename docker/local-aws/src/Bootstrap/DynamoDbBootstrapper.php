<?php

declare(strict_types=1);

namespace LocalAws\Bootstrap;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use LocalAws\Logging\JsonLogger;

/**
 * Brings DynamoDB Local to the state the application expects (ADR 0002): the table described by the schema file
 * exists, with the local secondary index LSI1, and TTL is on for `expiresAt` where the emulator allows it.
 *
 * Like the S3 bootstrap it reads first and writes only on a difference, so a second run says "no changes". It never
 * deletes a table: DynamoDB Local is in memory, so a table that differs is fixed by `make down && make up`, a choice
 * left to the developer.
 */
final readonly class DynamoDbBootstrapper
{
    /** Error codes with which an emulator says it does not know the operation (as opposed to a real failure). */
    private const TTL_UNSUPPORTED_CODES = ['UnknownOperationException', 'UnsupportedOperationException', 'InvalidAction'];

    public function __construct(
        private DynamoDbClient $dynamoDb,
        private JsonLogger $logger,
        private TableSchema $schema,
        private string $tableName,
    ) {}

    /**
     * @return int the number of changes made (0 when everything already matched)
     *
     * @throws TableDiffers when the table exists but differs from the schema
     * @throws DynamoDbException on any unexpected answer from the emulator
     */
    public function run(): int
    {
        $existing = $this->describe();
        $changes = $existing === null ? $this->createTable() : $this->verify($existing);

        if ($changes === 0) {
            $this->logger->info('no changes');
        } else {
            $this->logger->info('bootstrap finished', ['changes' => $changes]);
        }

        return $changes;
    }

    /**
     * @return array<array-key, mixed>|null null when the table does not exist
     */
    private function describe(): ?array
    {
        try {
            $table = $this->dynamoDb->describeTable(['TableName' => $this->tableName])->get('Table');
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            throw $e; // access denied and friends: never read as "missing", which would lead to a create
        }

        return is_array($table) ? $table : [];
    }

    /**
     * @param array<array-key, mixed> $table
     */
    private function verify(array $table): int
    {
        $differences = $this->schema->differencesFrom($table);
        if ($differences !== []) {
            throw new TableDiffers(sprintf(
                'Table %s already exists but differs from infra/dynamodb/table.json in: %s. The bootstrap never deletes a table; '
                . 'DynamoDB Local is in memory, so run `make down && make up` to start from an empty one.',
                $this->tableName,
                implode(', ', $differences),
            ));
        }

        return 0;
    }

    private function createTable(): int
    {
        try {
            // The parameters come from a JSON file, so they are passed as a generic command (the typed method wants literals).
            $this->dynamoDb->execute($this->dynamoDb->getCommand('CreateTable', $this->schema->createTableParameters($this->tableName)));
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceInUseException') {
                throw $e;
            }

            // Created by someone else between the check and the create: it must still be the table we want.
            return $this->verify($this->describe() ?? throw $e);
        }
        $this->dynamoDb->waitUntil('TableExists', ['TableName' => $this->tableName]);
        $this->logger->info('created table', ['table' => $this->tableName]);

        return 1 + (int) $this->enableTimeToLive();
    }

    /**
     * @return bool whether TTL was enabled (false when the emulator does not support it)
     */
    private function enableTimeToLive(): bool
    {
        try {
            $this->dynamoDb->updateTimeToLive([
                'TableName' => $this->tableName,
                'TimeToLiveSpecification' => ['Enabled' => true, 'AttributeName' => $this->schema->timeToLiveAttribute],
            ]);
        } catch (DynamoDbException $e) {
            if (!in_array($e->getAwsErrorCode(), self::TTL_UNSUPPORTED_CODES, true)) {
                throw $e;
            }
            $this->logger->info('TTL not supported locally', ['table' => $this->tableName, 'code' => $e->getAwsErrorCode()]);

            return false;
        }
        $this->logger->info('enabled TTL', ['table' => $this->tableName, 'attribute' => $this->schema->timeToLiveAttribute]);

        return true;
    }
}
