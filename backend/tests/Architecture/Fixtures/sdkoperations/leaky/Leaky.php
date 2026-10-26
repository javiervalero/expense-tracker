<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\SdkOperations\Leaky;

final class Leaky
{
    public function run(object $client, ?object $maybe): void
    {
        $client->getCommand('Query');
        $client->getCommand("Scan");
        $client->getCommand('ExecuteStatement');
        $client->scan([]);
        $client->scanAsync([]);
        $maybe?->queryAsync([]);
        $client->getPaginator('Query', []);
        $client->getIterator('Scan', []);
        $client::executeStatement([]);
        $client->EXECUTESTATEMENT([]);
        $client->getCommand('BatchExecuteStatement');
        $client->getCommand('ExecuteTransaction');
        $client->getCommand('BatchGetItem');
        $client->getCommand('TransactGetItems');
        $client->batchExecuteStatement([]);
        $client->executeTransaction([]);
        $client->batchGetItem([]);
        $client->transactGetItems([]);
        $maybe?->batchExecuteStatementAsync([]);
        $client->executeTransactionAsync([]);
        $client::batchGetItemAsync([]);
        $client->TransactGetItemsAsync([]);
    }
}
