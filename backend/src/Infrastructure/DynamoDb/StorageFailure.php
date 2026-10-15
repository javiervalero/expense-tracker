<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use RuntimeException;

/**
 * Any other storage failure (throttling, timeout, validation, access denied). The SDK exception is deliberately not
 * chained: its message can carry request details. Only the operation and the AWS error code are kept.
 */
final class StorageFailure extends RuntimeException
{
    public function __construct(public readonly string $operation, public readonly ?string $awsErrorCode)
    {
        parent::__construct('DynamoDB ' . $operation . ' failed' . ($awsErrorCode !== null ? ' (' . $awsErrorCode . ')' : ''));
    }
}
