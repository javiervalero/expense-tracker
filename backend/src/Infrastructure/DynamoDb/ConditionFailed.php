<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use RuntimeException;

/**
 * A condition expression was false (a duplicate, a missing item, a stale version). The message names the operation
 * only: never the item, the key or the condition values.
 */
final class ConditionFailed extends RuntimeException
{
    public function __construct(public readonly string $operation)
    {
        parent::__construct('DynamoDB ' . $operation . ' condition failed');
    }
}
