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
    /** The cancellation code of an operation whose condition expression did not hold. */
    public const string CONDITION_CHECK_FAILED = 'ConditionalCheckFailed';

    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly array $reasons,
        public readonly ?string $awsRequestId = null,
        public readonly ?int $httpStatusCode = null,
    ) {
        parent::__construct('DynamoDB transaction cancelled: [' . implode(', ', $reasons) . ']');
    }
}
