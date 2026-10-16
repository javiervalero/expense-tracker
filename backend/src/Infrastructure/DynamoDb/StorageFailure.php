<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use RuntimeException;

/**
 * Any other storage failure (throttling, timeout, validation, access denied, or a client-side error such as
 * `ClientError`). The SDK exception is deliberately not chained: its message can carry request details. Only the
 * operation, the error code, and (for support) the AWS request id and HTTP status are kept, as properties and never
 * in the message.
 */
final class StorageFailure extends RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly ?string $awsErrorCode,
        public readonly ?string $awsRequestId = null,
        public readonly ?int $httpStatusCode = null,
    ) {
        parent::__construct('DynamoDB ' . $operation . ' failed' . ($awsErrorCode !== null ? ' (' . $awsErrorCode . ')' : ''));
    }
}
