<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;

/**
 * A thin, typed adapter over the SDK. Items, keys and expression values are plain PHP arrays (integers stay
 * integers); expressions use `#name` and `:value` placeholders only, with the names and values passed alongside.
 * Reads are always strongly consistent (ADR 0002: a just-saved expense must show up in the next list).
 *
 * SDK failures become {@see ConditionFailed}, {@see TransactionCancelled} or {@see StorageFailure}; none of their
 * messages contain item data.
 */
final readonly class ItemStore
{
    private const CONDITION_FAILED = 'ConditionalCheckFailedException';
    private const TRANSACTION_CANCELLED = 'TransactionCanceledException';

    private Marshaler $marshaler;

    public function __construct(private DynamoDbClient $client, private string $tableName)
    {
        $this->marshaler = new Marshaler(['wrap_numbers' => false]);
    }

    /**
     * @param array<string, mixed> $key
     *
     * @return array<string, mixed>|null
     *
     * @throws StorageFailure
     */
    public function getItem(array $key): ?array
    {
        $result = $this->send('GetItem', [
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem($key),
            'ConsistentRead' => true,
        ]);
        $item = $result['Item'] ?? null;

        return is_array($item) ? $this->unmarshal($item) : null;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $names
     * @param array<string, mixed> $values
     *
     * @throws ConditionFailed
     * @throws StorageFailure
     */
    public function putItem(array $item, ?string $condition = null, array $names = [], array $values = []): void
    {
        $this->send('PutItem', ['TableName' => $this->tableName, 'Item' => $this->marshaler->marshalItem($item)]
            + $this->expressions($condition, $names, $values));
    }

    /**
     * @param array<string, mixed> $key
     * @param array<string, string> $names
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> the whole item after the update
     *
     * @throws ConditionFailed
     * @throws StorageFailure
     */
    public function updateItem(array $key, string $updateExpression, array $names, array $values, ?string $condition = null): array
    {
        $result = $this->send('UpdateItem', [
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem($key),
            'UpdateExpression' => $updateExpression,
            'ReturnValues' => 'ALL_NEW',
        ] + $this->expressions($condition, $names, $values));
        $attributes = $result['Attributes'] ?? [];

        return is_array($attributes) ? $this->unmarshal($attributes) : [];
    }

    /**
     * @param array<string, mixed> $key
     * @param array<string, string> $names
     * @param array<string, mixed> $values
     *
     * @throws ConditionFailed
     * @throws StorageFailure
     */
    public function deleteItem(array $key, ?string $condition = null, array $names = [], array $values = []): void
    {
        $this->send('DeleteItem', ['TableName' => $this->tableName, 'Key' => $this->marshaler->marshalItem($key)]
            + $this->expressions($condition, $names, $values));
    }

    /**
     * @param array<string, string> $names
     * @param array<string, mixed> $values
     * @param string|null $indexName a local secondary index (`TableLayout::LSI1`), or null for the base table
     * @param array<string, mixed>|null $exclusiveStartKey the previous page's `lastEvaluatedKey`
     *
     * @throws StorageFailure
     */
    public function query(
        string $keyCondition,
        array $names,
        array $values,
        ?string $indexName = null,
        bool $scanForward = true,
        ?int $limit = null,
        ?array $exclusiveStartKey = null,
    ): QueryPage {
        $request = [
            'TableName' => $this->tableName,
            'KeyConditionExpression' => $keyCondition,
            'ConsistentRead' => true,
            'ScanIndexForward' => $scanForward,
        ] + $this->expressions(null, $names, $values);
        if ($indexName !== null) {
            $request['IndexName'] = $indexName;
        }
        if ($limit !== null) {
            $request['Limit'] = $limit;
        }
        if ($exclusiveStartKey !== null) {
            $request['ExclusiveStartKey'] = $this->marshaler->marshalItem($exclusiveStartKey);
        }

        $result = $this->send('Query', $request);
        $rawItems = $result['Items'] ?? [];
        $items = [];
        foreach (is_array($rawItems) ? $rawItems : [] as $rawItem) {
            if (is_array($rawItem)) {
                $items[] = $this->unmarshal($rawItem);
            }
        }
        $lastKey = $result['LastEvaluatedKey'] ?? null;

        return new QueryPage($items, is_array($lastKey) ? $this->unmarshal($lastKey) : null);
    }

    /**
     * All operations commit together or none does.
     *
     * @param list<TransactionOperation> $operations
     *
     * @throws \InvalidArgumentException when there is nothing to write
     * @throws ConditionFailed
     * @throws TransactionCancelled
     * @throws StorageFailure
     */
    public function transactWrite(array $operations): void
    {
        if ($operations === []) {
            throw new \InvalidArgumentException('A transaction needs at least one operation.');
        }
        $items = [];
        foreach ($operations as $operation) {
            $items[] = [$operation->kind => $this->transactionBody($operation)];
        }

        $this->send('TransactWriteItems', ['TransactItems' => $items]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionBody(TransactionOperation $operation): array
    {
        $body = ['TableName' => $this->tableName]
            + ($operation->kind === TransactionOperation::PUT
                ? ['Item' => $this->marshaler->marshalItem($operation->keyOrItem)]
                : ['Key' => $this->marshaler->marshalItem($operation->keyOrItem)])
            + $this->expressions($operation->condition, $operation->names, $operation->values);
        if ($operation->updateExpression !== null) {
            $body['UpdateExpression'] = $operation->updateExpression;
        }

        return $body;
    }

    /**
     * @param array<string, string> $names
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function expressions(?string $condition, array $names, array $values): array
    {
        $parts = [];
        if ($condition !== null) {
            $parts['ConditionExpression'] = $condition;
        }
        if ($names !== []) {
            $parts['ExpressionAttributeNames'] = $names;
        }
        if ($values !== []) {
            $parts['ExpressionAttributeValues'] = $this->marshaler->marshalItem($values);
        }

        return $parts;
    }

    /**
     * @param array<array-key, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function unmarshal(array $item): array
    {
        /** @var array<string, mixed> */
        return $this->marshaler->unmarshalItem($item);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<array-key, mixed>
     */
    private function send(string $operation, array $request): array
    {
        try {
            return $this->client->execute($this->client->getCommand($operation, $request))->toArray();
        } catch (AwsException $e) {
            throw $this->mapped($operation, $e);
        }
    }

    private function mapped(string $operation, AwsException $e): ConditionFailed|TransactionCancelled|StorageFailure
    {
        $code = $e->getAwsErrorCode();
        if ($code === self::CONDITION_FAILED) {
            return new ConditionFailed($operation);
        }
        if ($code === self::TRANSACTION_CANCELLED) {
            return new TransactionCancelled($this->cancellationCodes($e));
        }

        return new StorageFailure($operation, $code);
    }

    /**
     * @return list<string> one code per operation; only the codes are read, never the `Item` or `Message` fields
     */
    private function cancellationCodes(AwsException $e): array
    {
        $reasons = $e->get('CancellationReasons');
        $codes = [];
        foreach (is_array($reasons) ? $reasons : [] as $reason) {
            $code = is_array($reason) ? ($reason['Code'] ?? null) : null;
            $codes[] = is_string($code) ? $code : 'Unknown';
        }

        return $codes;
    }
}
