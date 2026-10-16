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
     * @throws TableDiffers when the table exists but differs from the schema (TTL on another attribute included)
     * @throws DynamoDbException on any unexpected answer from the emulator
     */
    public function run(): int
    {
        $existing = $this->describe();
        $changes = $existing === null ? $this->createTable() : $this->verify($existing) + (int) $this->ensureTimeToLive();

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
        $created = 1;
        try {
            // The parameters come from a JSON file, so they are passed as a generic command (the typed method wants literals).
            $this->dynamoDb->execute($this->dynamoDb->getCommand('CreateTable', $this->schema->createTableParameters($this->tableName)));
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceInUseException') {
                throw $e;
            }

            // Created by someone else between the check and the create: it must still be the table we want, and it
            // may not be ACTIVE yet nor have TTL, so the wait and the TTL step below still apply.
            $created = $this->verify($this->describe() ?? throw $e);
        }
        $this->dynamoDb->waitUntil('TableExists', ['TableName' => $this->tableName]);
        if ($created === 1) {
            $this->logger->info('created table', ['table' => $this->tableName]);
        }

        return $created + (int) $this->ensureTimeToLive();
    }

    /**
     * Makes sure TTL is on for the schema's attribute, also on a table that already existed (an earlier run may have
     * failed at this step). TTL on another attribute cannot be moved in place (DynamoDB has to disable it first and the
     * old one lingers), so it is a difference like any other and the developer decides.
     *
     * @return bool whether TTL was enabled now (false when it already was, or when the emulator does not support it)
     *
     * @throws TableDiffers when TTL is on for another attribute
     */
    private function ensureTimeToLive(): bool
    {
        try {
            $ttl = $this->dynamoDb->describeTimeToLive(['TableName' => $this->tableName])->get('TimeToLiveDescription');
            $ttl = is_array($ttl) ? $ttl : [];
            $isOn = in_array($ttl['TimeToLiveStatus'] ?? null, ['ENABLED', 'ENABLING'], true);
            if ($isOn && ($ttl['AttributeName'] ?? null) !== $this->schema->timeToLiveAttribute) {
                throw new TableDiffers(sprintf(
                    'Table %s already exists but differs from infra/dynamodb/table.json in: TimeToLive (on %s instead of %s). '
                    . 'The bootstrap never deletes a table or switches TTL; DynamoDB Local is in memory, so run `make down && make up` to start from an empty one.',
                    $this->tableName,
                    is_string($ttl['AttributeName'] ?? null) ? $ttl['AttributeName'] : 'another attribute',
                    $this->schema->timeToLiveAttribute,
                ));
            }
            if ($isOn) {
                return false;
            }
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
