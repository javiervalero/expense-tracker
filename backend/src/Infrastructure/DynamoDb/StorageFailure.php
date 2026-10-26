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
    /** The error code of a stored item that does not have the shape the code wrote: a bug or tampering, not a storage hiccup. */
    public const string CORRUPT_ITEM = 'CorruptItem';

    /** The error code of a query that returned a user's `PROFILE` item: a key condition that is not restricted to the sort-key prefix. A security event, logged as such. */
    public const string PROFILE_IN_QUERY = 'ProfileInQuery';

    public function __construct(
        public readonly string $operation,
        public readonly ?string $awsErrorCode,
        public readonly ?string $awsRequestId = null,
        public readonly ?int $httpStatusCode = null,
    ) {
        parent::__construct('DynamoDB ' . $operation . ' failed' . ($awsErrorCode !== null ? ' (' . $awsErrorCode . ')' : ''));
    }
}
