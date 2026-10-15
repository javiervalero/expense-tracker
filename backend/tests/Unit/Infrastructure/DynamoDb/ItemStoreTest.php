<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\DynamoDb;

use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\DynamoDb\TransactionOperation;
use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Command;
use Aws\Exception\CredentialsException;
use Aws\Exception\UnresolvedEndpointException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemStoreTest extends TestCase
{
    private const TABLE = 'expense-tracker-test';
    private const PRIVATE_NOTE = 'private-note-xyz-123';

    /** @var list<array{name: string, args: array<array-key, mixed>}> */
    private array $sent = [];
    private MockHandler $mock;

    protected function setUp(): void
    {
        $this->sent = [];
        $this->mock = new MockHandler();
    }

    private function store(): ItemStore
    {
        $client = new DynamoDbClient([
            'version' => '2012-08-10',
            'region' => 'eu-west-1',
            'credentials' => ['key' => 'localdev', 'secret' => 'localdevsecret'],
            'handler' => $this->mock,
            'retries' => 0,
        ]);
        $client->getHandlerList()->appendInit(function (callable $next): callable {
            return function (CommandInterface $command) use ($next): mixed {
                $this->sent[] = ['name' => $command->getName(), 'args' => $command->toArray()];

                return $next($command);
            };
        }, 'record');

        return new ItemStore($client, self::TABLE);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function failure(string $code, string $message = 'boom', array $body = []): DynamoDbException
    {
        return new DynamoDbException($message, new Command('X'), ['code' => $code, 'body' => $body]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function onlyRequest(string $name): array
    {
        self::assertCount(1, $this->sent);
        self::assertSame($name, $this->sent[0]['name']);

        return $this->sent[0]['args'];
    }

    public function testGetItemIsAlwaysConsistentAndMarshalsTheKey(): void
    {
        $this->mock->append(new Result(['Item' => ['PK' => ['S' => 'USER#1'], 'SK' => ['S' => 'PROFILE'], 'amountCents' => ['N' => '1250']]]));

        $item = $this->store()->getItem(['PK' => 'USER#1', 'SK' => 'PROFILE']);

        self::assertSame(['PK' => 'USER#1', 'SK' => 'PROFILE', 'amountCents' => 1250], $item);
        $args = $this->onlyRequest('GetItem');
        self::assertSame(self::TABLE, $args['TableName']);
        self::assertTrue($args['ConsistentRead']);
        self::assertSame(['PK' => ['S' => 'USER#1'], 'SK' => ['S' => 'PROFILE']], $args['Key']);
    }

    public function testGetItemReturnsNullWhenTheItemIsMissing(): void
    {
        $this->mock->append(new Result([]));

        self::assertNull($this->store()->getItem(['PK' => 'USER#1', 'SK' => 'PROFILE']));
    }

    public function testPutItemWithoutConditionSendsNoExpressionMaps(): void
    {
        $this->mock->append(new Result([]));

        $this->store()->putItem(['PK' => 'A', 'SK' => 'B', 'amountCents' => 5, 'note' => '']);

        $args = $this->onlyRequest('PutItem');
        self::assertSame(['PK' => ['S' => 'A'], 'SK' => ['S' => 'B'], 'amountCents' => ['N' => '5'], 'note' => ['S' => '']], $args['Item']);
        self::assertArrayNotHasKey('ConditionExpression', $args);
        self::assertArrayNotHasKey('ExpressionAttributeNames', $args);
        self::assertArrayNotHasKey('ExpressionAttributeValues', $args);
    }

    public function testPutItemSendsConditionWithNamesAndMarshaledValues(): void
    {
        $this->mock->append(new Result([]));

        $this->store()->putItem(['PK' => 'A', 'SK' => 'B'], 'attribute_not_exists(#pk) OR #v = :v', ['#pk' => 'PK', '#v' => 'version'], [':v' => 3]);

        $args = $this->onlyRequest('PutItem');
        self::assertSame('attribute_not_exists(#pk) OR #v = :v', $args['ConditionExpression']);
        self::assertSame(['#pk' => 'PK', '#v' => 'version'], $args['ExpressionAttributeNames']);
        self::assertSame([':v' => ['N' => '3']], $args['ExpressionAttributeValues']);
    }

    public function testUpdateItemReturnsAllNewAttributes(): void
    {
        $this->mock->append(new Result(['Attributes' => ['PK' => ['S' => 'A'], 'SK' => ['S' => 'B'], 'amountCents' => ['N' => '900']]]));

        $item = $this->store()->updateItem(['PK' => 'A', 'SK' => 'B'], 'SET #a = :a', ['#a' => 'amountCents', '#pk' => 'PK'], [':a' => 900], 'attribute_exists(#pk)');

        self::assertSame(['PK' => 'A', 'SK' => 'B', 'amountCents' => 900], $item);
        $args = $this->onlyRequest('UpdateItem');
        self::assertSame('ALL_NEW', $args['ReturnValues']);
        self::assertSame('SET #a = :a', $args['UpdateExpression']);
        self::assertSame('attribute_exists(#pk)', $args['ConditionExpression']);
        self::assertSame([':a' => ['N' => '900']], $args['ExpressionAttributeValues']);
        self::assertSame(['PK' => ['S' => 'A'], 'SK' => ['S' => 'B']], $args['Key']);
    }

    public function testUpdateItemWithoutValuesOrConditionOmitsThem(): void
    {
        $this->mock->append(new Result(['Attributes' => ['PK' => ['S' => 'A']]]));

        $this->store()->updateItem(['PK' => 'A', 'SK' => 'B'], 'REMOVE #n', ['#n' => 'note'], []);

        $args = $this->onlyRequest('UpdateItem');
        self::assertArrayNotHasKey('ExpressionAttributeValues', $args);
        self::assertArrayNotHasKey('ConditionExpression', $args);
    }

    public function testDeleteItemSendsKeyAndCondition(): void
    {
        $this->mock->append(new Result([]));

        $this->store()->deleteItem(['PK' => 'A', 'SK' => 'B'], 'attribute_exists(#pk)', ['#pk' => 'PK']);

        $args = $this->onlyRequest('DeleteItem');
        self::assertSame(['PK' => ['S' => 'A'], 'SK' => ['S' => 'B']], $args['Key']);
        self::assertSame('attribute_exists(#pk)', $args['ConditionExpression']);
        self::assertSame(['#pk' => 'PK'], $args['ExpressionAttributeNames']);
    }

    public function testQuerySendsAConsistentDescendingIndexQueryWithPaging(): void
    {
        $this->mock->append(new Result([
            'Items' => [['PK' => ['S' => 'USER#1'], 'LSI1SK' => ['S' => 'EXP#2026-01-02#b']], ['PK' => ['S' => 'USER#1'], 'LSI1SK' => ['S' => 'EXP#2026-01-01#a']]],
            'LastEvaluatedKey' => ['PK' => ['S' => 'USER#1'], 'SK' => ['S' => 'EXP#a'], 'LSI1SK' => ['S' => 'EXP#2026-01-01#a']],
        ]));

        $page = $this->store()->query(
            '#pk = :pk AND #idx BETWEEN :from AND :to',
            ['#pk' => 'PK', '#idx' => 'LSI1SK'],
            [':pk' => 'USER#1', ':from' => 'EXP#2026-01-01', ':to' => 'EXP#2026-01-31~'],
            indexName: 'LSI1',
            scanForward: false,
            limit: 2,
            exclusiveStartKey: ['PK' => 'USER#1', 'SK' => 'EXP#z', 'LSI1SK' => 'EXP#2026-02-01#z'],
        );

        self::assertCount(2, $page->items);
        self::assertSame('EXP#2026-01-02#b', $page->items[0]['LSI1SK']);
        self::assertSame(['PK' => 'USER#1', 'SK' => 'EXP#a', 'LSI1SK' => 'EXP#2026-01-01#a'], $page->lastEvaluatedKey);
        $args = $this->onlyRequest('Query');
        self::assertSame('LSI1', $args['IndexName']);
        self::assertTrue($args['ConsistentRead']);
        self::assertFalse($args['ScanIndexForward']);
        self::assertSame(2, $args['Limit']);
        self::assertSame('#pk = :pk AND #idx BETWEEN :from AND :to', $args['KeyConditionExpression']);
        self::assertSame(['#pk' => 'PK', '#idx' => 'LSI1SK'], $args['ExpressionAttributeNames']);
        self::assertSame([':pk' => ['S' => 'USER#1'], ':from' => ['S' => 'EXP#2026-01-01'], ':to' => ['S' => 'EXP#2026-01-31~']], $args['ExpressionAttributeValues']);
        self::assertSame(['PK' => ['S' => 'USER#1'], 'SK' => ['S' => 'EXP#z'], 'LSI1SK' => ['S' => 'EXP#2026-02-01#z']], $args['ExclusiveStartKey']);
    }

    public function testQueryDefaultsToTheBaseTableAscendingWithoutLimit(): void
    {
        $this->mock->append(new Result(['Items' => []]));

        $page = $this->store()->query('#pk = :pk', ['#pk' => 'PK'], [':pk' => 'USER#1']);

        self::assertSame([], $page->items);
        self::assertNull($page->lastEvaluatedKey);
        $args = $this->onlyRequest('Query');
        self::assertArrayNotHasKey('IndexName', $args);
        self::assertArrayNotHasKey('Limit', $args);
        self::assertArrayNotHasKey('ExclusiveStartKey', $args);
        self::assertTrue($args['ScanIndexForward']);
        self::assertTrue($args['ConsistentRead']);
    }

    public function testTransactWriteSendsEveryOperationKind(): void
    {
        $this->mock->append(new Result([]));

        $this->store()->transactWrite([
            TransactionOperation::put(['PK' => 'USER#1', 'SK' => 'PROFILE'], 'attribute_not_exists(#pk)', ['#pk' => 'PK']),
            TransactionOperation::update(['PK' => 'U', 'SK' => 'S'], 'SET #a = :a', ['#a' => 'a'], [':a' => 1], 'attribute_exists(#a)'),
            TransactionOperation::delete(['PK' => 'D', 'SK' => 'S']),
            TransactionOperation::conditionCheck(['PK' => 'C', 'SK' => 'S'], 'attribute_exists(#pk)', ['#pk' => 'PK']),
        ]);

        $args = $this->onlyRequest('TransactWriteItems');
        /** @var list<array<string, array<string, mixed>>> $items */
        $items = $args['TransactItems'];
        self::assertCount(4, $items);
        self::assertSame(self::TABLE, $items[0]['Put']['TableName']);
        self::assertSame(['PK' => ['S' => 'USER#1'], 'SK' => ['S' => 'PROFILE']], $items[0]['Put']['Item']);
        self::assertSame('attribute_not_exists(#pk)', $items[0]['Put']['ConditionExpression']);
        self::assertSame(['#pk' => 'PK'], $items[0]['Put']['ExpressionAttributeNames']);
        self::assertSame('SET #a = :a', $items[1]['Update']['UpdateExpression']);
        self::assertSame([':a' => ['N' => '1']], $items[1]['Update']['ExpressionAttributeValues']);
        self::assertSame(['PK' => ['S' => 'D'], 'SK' => ['S' => 'S']], $items[2]['Delete']['Key']);
        self::assertArrayNotHasKey('ConditionExpression', $items[2]['Delete']);
        self::assertSame('attribute_exists(#pk)', $items[3]['ConditionCheck']['ConditionExpression']);
        self::assertSame(self::TABLE, $items[3]['ConditionCheck']['TableName']);
    }

    public function testTransactWriteWithNoOperationsIsRejectedBeforeAnyCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->store()->transactWrite([]);
        } finally {
            self::assertSame([], $this->sent);
        }
    }

    public function testConditionalCheckFailureBecomesConditionFailed(): void
    {
        $this->mock->append($this->failure('ConditionalCheckFailedException', 'The conditional request failed ' . self::PRIVATE_NOTE));

        try {
            $this->store()->putItem(['PK' => 'A', 'SK' => 'B', 'note' => self::PRIVATE_NOTE], 'attribute_not_exists(#pk)', ['#pk' => 'PK']);
            self::fail('Expected ConditionFailed');
        } catch (ConditionFailed $e) {
            self::assertSame('PutItem', $e->operation);
            self::assertStringNotContainsString(self::PRIVATE_NOTE, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testUpdateAndDeleteMapConditionFailuresToo(): void
    {
        $this->mock->append($this->failure('ConditionalCheckFailedException'), $this->failure('ConditionalCheckFailedException'));
        $store = $this->store();

        try {
            $store->updateItem(['PK' => 'A', 'SK' => 'B'], 'SET #a = :a', ['#a' => 'a'], [':a' => 1], 'attribute_exists(#a)');
            self::fail('Expected ConditionFailed');
        } catch (ConditionFailed $e) {
            self::assertSame('UpdateItem', $e->operation);
        }
        try {
            $store->deleteItem(['PK' => 'A', 'SK' => 'B'], 'attribute_exists(#a)', ['#a' => 'a']);
            self::fail('Expected ConditionFailed');
        } catch (ConditionFailed $e) {
            self::assertSame('DeleteItem', $e->operation);
        }
    }

    public function testCancelledTransactionCarriesTheReasonCodes(): void
    {
        $this->mock->append($this->failure('TransactionCanceledException', 'Transaction cancelled ' . self::PRIVATE_NOTE, [
            'CancellationReasons' => [
                ['Code' => 'ConditionalCheckFailed', 'Message' => 'The conditional request failed', 'Item' => ['note' => ['S' => self::PRIVATE_NOTE]]],
                ['Code' => 'None'],
            ],
        ]));

        try {
            $this->store()->transactWrite([TransactionOperation::put(['PK' => 'A', 'SK' => 'B'])]);
            self::fail('Expected TransactionCancelled');
        } catch (TransactionCancelled $e) {
            self::assertSame(['ConditionalCheckFailed', 'None'], $e->reasons);
            self::assertStringContainsString('ConditionalCheckFailed', $e->getMessage());
            self::assertStringNotContainsString(self::PRIVATE_NOTE, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testCancelledTransactionWithoutReasonsHasAnEmptyList(): void
    {
        $this->mock->append($this->failure('TransactionCanceledException'));

        try {
            $this->store()->transactWrite([TransactionOperation::delete(['PK' => 'A', 'SK' => 'B'])]);
            self::fail('Expected TransactionCancelled');
        } catch (TransactionCancelled $e) {
            self::assertSame([], $e->reasons);
        }
    }

    public function testAnyOtherFailureBecomesStorageFailureWithoutItemData(): void
    {
        $this->mock->append($this->failure('ProvisionedThroughputExceededException', 'slow down ' . self::PRIVATE_NOTE));

        try {
            $this->store()->putItem(['PK' => 'A', 'SK' => 'B', 'note' => self::PRIVATE_NOTE]);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('PutItem', $e->operation);
            self::assertSame('ProvisionedThroughputExceededException', $e->awsErrorCode);
            self::assertStringContainsString('PutItem', $e->getMessage());
            self::assertStringContainsString('ProvisionedThroughputExceededException', $e->getMessage());
            self::assertStringNotContainsString(self::PRIVATE_NOTE, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testFailureWithoutAnAwsCodeStillMapsToStorageFailure(): void
    {
        $this->mock->append(new DynamoDbException('connection refused', new Command('GetItem')));

        try {
            $this->store()->getItem(['PK' => 'A', 'SK' => 'B']);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('GetItem', $e->operation);
            self::assertNull($e->awsErrorCode);
            self::assertNull($e->awsRequestId);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('connection refused', $e->getMessage());
        }
    }

    public function testQueryFailureIsMappedToStorageFailure(): void
    {
        $this->mock->append($this->failure('InternalServerError'));

        try {
            $this->store()->query('#pk = :pk', ['#pk' => 'PK'], [':pk' => 'x']);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('Query', $e->operation);
        }
    }

    public function testTransactWriteMapsAnyOtherFailureToStorageFailure(): void
    {
        $this->mock->append($this->failure('InternalServerError'));

        try {
            $this->store()->transactWrite([TransactionOperation::put(['PK' => 'A', 'SK' => 'B'])]);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('TransactWriteItems', $e->operation);
            self::assertSame('InternalServerError', $e->awsErrorCode);
        }
    }

    public function testStorageFailureKeepsTheRequestIdAndStatusOutOfTheMessage(): void
    {
        $this->mock->append(new DynamoDbException('slow down ' . self::PRIVATE_NOTE, new Command('PutItem'), [
            'code' => 'ThrottlingException',
            'request_id' => 'REQ-ABC-123',
            'response' => new Response(400),
        ]));

        try {
            $this->store()->putItem(['PK' => 'A', 'SK' => 'B']);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('REQ-ABC-123', $e->awsRequestId);
            self::assertSame(400, $e->httpStatusCode);
            self::assertStringNotContainsString('REQ-ABC-123', $e->getMessage());
            self::assertStringNotContainsString('400', $e->getMessage());
        }
    }

    public function testTransactionCancelledAndConditionFailedKeepTheRequestId(): void
    {
        $context = ['request_id' => 'REQ-XYZ', 'response' => new Response(400)];
        $this->mock->append(
            new DynamoDbException('x', new Command('PutItem'), ['code' => 'ConditionalCheckFailedException'] + $context),
            new DynamoDbException('x', new Command('TransactWriteItems'), ['code' => 'TransactionCanceledException'] + $context),
        );
        $store = $this->store();

        try {
            $store->putItem(['PK' => 'A', 'SK' => 'B'], 'attribute_not_exists(#pk)', ['#pk' => 'PK']);
            self::fail('Expected ConditionFailed');
        } catch (ConditionFailed $e) {
            self::assertSame('REQ-XYZ', $e->awsRequestId);
            self::assertSame(400, $e->httpStatusCode);
        }
        try {
            $store->transactWrite([TransactionOperation::put(['PK' => 'A', 'SK' => 'B'])]);
            self::fail('Expected TransactionCancelled');
        } catch (TransactionCancelled $e) {
            self::assertSame('REQ-XYZ', $e->awsRequestId);
            self::assertSame(400, $e->httpStatusCode);
        }
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function clientSideFailures(): iterable
    {
        yield 'credentials' => [new CredentialsException('no credentials ' . self::PRIVATE_NOTE)];
        yield 'unresolved endpoint' => [new UnresolvedEndpointException('bad endpoint ' . self::PRIVATE_NOTE)];
        yield 'invalid argument' => [new \InvalidArgumentException('bad argument ' . self::PRIVATE_NOTE)];
        yield 'unexpected value' => [new \UnexpectedValueException('bad value ' . self::PRIVATE_NOTE)];
    }

    #[DataProvider('clientSideFailures')]
    public function testClientSideFailuresBecomeAnUnchainedStorageFailure(\Throwable $failure): void
    {
        $this->mock->append($failure);

        try {
            $this->store()->putItem(['PK' => 'A', 'SK' => 'B', 'note' => self::PRIVATE_NOTE]);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('PutItem', $e->operation);
            self::assertSame('ClientError', $e->awsErrorCode);
            self::assertNull($e->awsRequestId);
            self::assertNull($e->httpStatusCode);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::PRIVATE_NOTE, $e->getMessage());
        }
    }

    public function testAnUnmarshalableValueBecomesAStorageFailureWithoutItemData(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        try {
            $this->store()->putItem(['PK' => 'A', 'SK' => 'B', self::PRIVATE_NOTE => $resource]);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('PutItem', $e->operation);
            self::assertSame('ClientError', $e->awsErrorCode);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::PRIVATE_NOTE, $e->getMessage());
            self::assertSame([], $this->sent);
        } finally {
            fclose($resource);
        }
    }

    public function testAnUnmarshalableKeyAndTransactionValueAreMappedToo(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);
        $store = $this->store();

        try {
            $store->getItem(['PK' => $resource]);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('GetItem', $e->operation);
        }
        try {
            $store->transactWrite([TransactionOperation::put(['PK' => $resource])]);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('TransactWriteItems', $e->operation);
        } finally {
            fclose($resource);
        }
    }

    public function testAnSdkValidationFailureBecomesAStorageFailure(): void
    {
        $client = new DynamoDbClient([
            'version' => '2012-08-10',
            'region' => 'eu-west-1',
            'credentials' => ['key' => 'localdev', 'secret' => 'localdevsecret'],
            'handler' => $this->mock,
        ]);
        $store = new ItemStore($client, '');

        try {
            $store->getItem(['PK' => self::PRIVATE_NOTE, 'SK' => 'B']);
            self::fail('Expected StorageFailure');
        } catch (StorageFailure $e) {
            self::assertSame('ClientError', $e->awsErrorCode);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::PRIVATE_NOTE, $e->getMessage());
        }
    }

    public function testQueryAcceptsOnlyTheBaseTableOrTheFirstLocalIndex(): void
    {
        $this->mock->append(new Result(['Items' => []]));

        try {
            $this->store()->query('#pk = :pk', ['#pk' => 'PK'], [':pk' => 'x'], indexName: 'GSI1');
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->sent);
        }
        $this->store()->query('#pk = :pk', ['#pk' => 'PK'], [':pk' => 'x'], indexName: TableLayout::LSI1);
        self::assertSame(TableLayout::LSI1, $this->onlyRequest('Query')['IndexName']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above the maximum' => [ItemStore::MAX_QUERY_LIMIT + 1];
    }

    #[DataProvider('invalidLimits')]
    public function testQueryRejectsALimitOutsideTheAllowedRangeBeforeAnyCall(int $limit): void
    {
        try {
            $this->store()->query('#pk = :pk', ['#pk' => 'PK'], [':pk' => 'x'], limit: $limit);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->sent);
        }
    }

    public function testQueryAcceptsTheLimitBoundaries(): void
    {
        $this->mock->append(new Result(['Items' => []]), new Result(['Items' => []]));
        $store = $this->store();

        $store->query('#pk = :pk', ['#pk' => 'PK'], [':pk' => 'x'], limit: 1);
        $store->query('#pk = :pk', ['#pk' => 'PK'], [':pk' => 'x'], limit: ItemStore::MAX_QUERY_LIMIT);

        self::assertSame([1, 1000], array_map(static fn(array $call): mixed => $call['args']['Limit'], $this->sent));
    }
}
