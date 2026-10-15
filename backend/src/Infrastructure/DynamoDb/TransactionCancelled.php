<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use RuntimeException;

/**
 * A transaction was cancelled. `reasons` holds one DynamoDB cancellation code per operation, in order
 * (`None`, `ConditionalCheckFailed`, `TransactionConflict`...), so a caller can tell which guard fired.
 */
final class TransactionCancelled extends RuntimeException
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('DynamoDB transaction cancelled: [' . implode(', ', $reasons) . ']');
    }
}
