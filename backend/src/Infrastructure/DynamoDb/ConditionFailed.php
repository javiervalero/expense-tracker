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
    public function __construct(
        public readonly string $operation,
        public readonly ?string $awsRequestId = null,
        public readonly ?int $httpStatusCode = null,
    ) {
        parent::__construct('DynamoDB ' . $operation . ' condition failed');
    }
}
