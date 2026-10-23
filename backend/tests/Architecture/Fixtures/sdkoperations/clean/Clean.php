<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\SdkOperations\Clean;

/**
 * Prose may mention 'Query', Scan and ->scan() without using them.
 */
final class Clean implements \IteratorAggregate
{
    public object $scan;

    public function getIterator(): \EmptyIterator
    {
        // $client->scan() in a comment is fine.
        return new \EmptyIterator();
    }

    public function run(object $store): array
    {
        $note = 'Queryable and Scanner are not operations';
        $other = ['GetItem', 'PutItem', 'query is lower case text', 'BatchGetItems', 'ExecuteTransactions'];

        return [$store->scanned(), $store->scan, $store->executeStatements(), $note, $other];
    }
}
