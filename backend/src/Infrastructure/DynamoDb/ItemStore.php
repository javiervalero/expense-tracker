<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Api\Parser\Exception\ParserException;
use Aws\Exception\UnresolvedEndpointException;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * A thin, typed adapter over the SDK. Items, keys and expression values are plain PHP arrays (integers stay
 * integers); expressions use `#name` and `:value` placeholders only, with the names and values passed alongside.
 * Reads are always strongly consistent (ADR 0002: a just-saved expense must show up in the next list).
 *
 * Every expression string is a `literal-string` and every expression-attribute-name map holds literal strings, so
 * PHPStan rejects an expression built from input; values travel only in the values maps.
 *
 * SDK failures become {@see ConditionFailed}, {@see TransactionCancelled} or {@see StorageFailure}; none of their
 * messages contain item data, and none chains the original exception. Client-side failures (credentials, endpoint
 * resolution, SDK validation, marshalling a value) become a StorageFailure with the code `ClientError`.
 */
final readonly class ItemStore
{
    private const CONDITION_FAILED = 'ConditionalCheckFailedException';
    private const TRANSACTION_CANCELLED = 'TransactionCanceledException';
    private const CLIENT_ERROR = 'ClientError';
    private const ATTRIBUTE_TYPES = ['S', 'N', 'B', 'BOOL', 'NULL', 'SS', 'NS', 'BS', 'L', 'M'];
    /** DynamoDB's own cap on `Limit` is the table size; 1000 keeps one page well inside the 1 MB response cap. */
    public const int MAX_QUERY_LIMIT = 1000;

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
        $result = $this->send('GetItem', fn(): array => [
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem($key),
            'ConsistentRead' => true,
        ]);
        $item = $result['Item'] ?? null;

        return is_array($item) ? $this->unmarshal('GetItem', $item) : null;
    }

    /**
     * @param array<string, mixed> $item
     * @param literal-string|null $condition
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     *
     * @throws ConditionFailed
     * @throws StorageFailure
     */
    public function putItem(array $item, ?string $condition = null, array $names = [], array $values = []): void
    {
        $this->send('PutItem', fn(): array => ['TableName' => $this->tableName, 'Item' => $this->marshaler->marshalItem($item)]
            + $this->expressions($condition, $names, $values));
    }

    /**
     * @param array<string, mixed> $key
     * @param literal-string $updateExpression
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     * @param literal-string|null $condition
     *
     * @return array<string, mixed> the whole item after the update
     *
     * @throws ConditionFailed
     * @throws StorageFailure
     */
    public function updateItem(array $key, string $updateExpression, array $names, array $values, ?string $condition = null): array
    {
        $result = $this->send('UpdateItem', fn(): array => [
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem($key),
            'UpdateExpression' => $updateExpression,
            'ReturnValues' => 'ALL_NEW',
        ] + $this->expressions($condition, $names, $values));
        $attributes = $result['Attributes'] ?? [];

        return is_array($attributes) ? $this->unmarshal('UpdateItem', $attributes) : [];
    }

    /**
     * @param array<string, mixed> $key
     * @param literal-string|null $condition
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     *
     * @throws ConditionFailed
     * @throws StorageFailure
     */
    public function deleteItem(array $key, ?string $condition = null, array $names = [], array $values = []): void
    {
        $this->send('DeleteItem', fn(): array => ['TableName' => $this->tableName, 'Key' => $this->marshaler->marshalItem($key)]
            + $this->expressions($condition, $names, $values));
    }

    /**
     * @param literal-string $keyCondition
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     * @param string|null $indexName `TableLayout::LSI1`, or null for the base table
     * @param int|null $limit 1 to {@see self::MAX_QUERY_LIMIT} items, or null for no limit
     * @param array<string, mixed>|null $exclusiveStartKey the previous page's `lastEvaluatedKey`
     *
     * @throws InvalidArgumentException for an unknown index or a limit outside the range (nothing is sent)
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
        if ($indexName !== null && $indexName !== TableLayout::LSI1) {
            throw new InvalidArgumentException('Unknown index.');
        }
        if ($limit !== null && ($limit < 1 || $limit > self::MAX_QUERY_LIMIT)) {
            throw new InvalidArgumentException(sprintf('The limit must be between 1 and %d.', self::MAX_QUERY_LIMIT));
        }

        $result = $this->send('Query', fn(): array => $this->queryRequest($keyCondition, $names, $values, $indexName, $scanForward, $limit, $exclusiveStartKey));
        $rawItems = $result['Items'] ?? [];
        $items = [];
        foreach (is_array($rawItems) ? $rawItems : [] as $rawItem) {
            if (is_array($rawItem)) {
                $items[] = $this->unmarshal('Query', $rawItem);
            }
        }
        $lastKey = $result['LastEvaluatedKey'] ?? null;

        return new QueryPage($items, is_array($lastKey) ? $this->unmarshal('Query', $lastKey) : null);
    }

    /**
     * @param literal-string $keyCondition
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $exclusiveStartKey
     *
     * @return array<string, mixed>
     */
    private function queryRequest(string $keyCondition, array $names, array $values, ?string $indexName, bool $scanForward, ?int $limit, ?array $exclusiveStartKey): array
    {
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

        return $request;
    }

    /**
     * All operations commit together or none does.
     *
     * @param list<TransactionOperation> $operations
     *
     * @throws InvalidArgumentException when there is nothing to write
     * @throws TransactionCancelled
     * @throws StorageFailure
     */
    public function transactWrite(array $operations): void
    {
        if ($operations === []) {
            throw new InvalidArgumentException('A transaction needs at least one operation.');
        }
        $this->send('TransactWriteItems', function () use ($operations): array {
            $items = [];
            foreach ($operations as $operation) {
                $items[] = [$operation->kind => $this->transactionBody($operation)];
            }

            return ['TransactItems' => $items];
        });
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
     * @param literal-string|null $condition
     * @param array<string, literal-string> $names
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
     *
     * @throws StorageFailure
     */
    private function unmarshal(string $operation, array $item): array
    {
        try {
            // The SDK raises PHP warnings (not only exceptions) on malformed values, so check the shape first.
            foreach ($item as $value) {
                self::assertAttributeValue($value);
            }
            /** @var array<string, mixed> */
            return $this->marshaler->unmarshalItem($item);
        } catch (UnexpectedValueException|InvalidArgumentException) {
            // Not chained, and the message is dropped: it can quote the response data.
            throw new StorageFailure($operation, self::CLIENT_ERROR);
        }
    }

    /**
     * @throws UnexpectedValueException when the value is not a DynamoDB typed attribute value
     */
    private static function assertAttributeValue(mixed $value): void
    {
        if (!is_array($value) || count($value) !== 1) {
            throw new UnexpectedValueException('Malformed attribute value.');
        }
        $type = array_key_first($value);
        $inner = $value[$type];
        if (!in_array($type, self::ATTRIBUTE_TYPES, true)) {
            throw new UnexpectedValueException('Unknown attribute type.');
        }
        if ($type === 'L' || $type === 'M') {
            if (!is_array($inner)) {
                throw new UnexpectedValueException('Malformed attribute value.');
            }
            foreach ($inner as $child) {
                self::assertAttributeValue($child);
            }
        }
    }

    /**
     * Builds the request inside the mapped region, so a value the marshaler refuses is reported like any other
     * client-side failure.
     *
     * @param callable(): array<string, mixed> $buildRequest
     *
     * @return array<array-key, mixed>
     *
     * @throws ConditionFailed
     * @throws TransactionCancelled
     * @throws StorageFailure
     */
    private function send(string $operation, callable $buildRequest): array
    {
        try {
            return $this->client->execute($this->client->getCommand($operation, $buildRequest()))->toArray();
        } catch (AwsException $e) {
            throw $this->mapped($operation, $e);
        } catch (CredentialsException|UnresolvedEndpointException|ParserException|InvalidArgumentException|UnexpectedValueException) {
            // Not chained, and the message is dropped: it can quote the offending value.
            throw new StorageFailure($operation, self::CLIENT_ERROR);
        }
    }

    private function mapped(string $operation, AwsException $e): ConditionFailed|TransactionCancelled|StorageFailure
    {
        $code = $e->getAwsErrorCode();
        $requestId = $e->getAwsRequestId();
        $status = $e->getStatusCode();
        if ($code === self::CONDITION_FAILED) {
            return new ConditionFailed($operation, $requestId, $status);
        }
        if ($code === self::TRANSACTION_CANCELLED) {
            return new TransactionCancelled($this->cancellationCodes($e), $requestId, $status);
        }

        return new StorageFailure($operation, $code, $requestId, $status);
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
