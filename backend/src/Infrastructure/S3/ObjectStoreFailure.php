<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use Aws\Exception\AwsException;

/**
 * An S3 call failed. It carries only fields that are safe to log: the operation, S3's error code (or our own, such as
 * `TooLarge` or `UnexpectedResponse`), the HTTP status, S3's request id, the class of the original exception and how
 * many errors there were. The SDK's own message can hold the request URL and its signature, so it is never kept (no
 * `previous`, no `getMessage()` of the original).
 */
final class ObjectStoreFailure extends \RuntimeException
{
    /** The code of a read that was refused because the object is bigger than the caller's limit. */
    public const string TOO_LARGE = 'TooLarge';

    public function __construct(
        public readonly string $operation,
        public readonly ?string $errorCode,
        public readonly ?int $statusCode = null,
        public readonly ?string $requestId = null,
        public readonly ?string $exceptionClass = null,
        public readonly int $errorCount = 1,
    ) {
        parent::__construct('S3 ' . $operation . ' failed' . ($errorCode !== null ? ' (' . $errorCode . ')' : ''));
    }

    /** From an exception of the SDK (or of anything else that went wrong while calling it), keeping only the safe fields. */
    public static function fromSdk(string $operation, \RuntimeException $e): self
    {
        if (!$e instanceof AwsException) {
            return new self($operation, null, null, null, $e::class);
        }
        $requestId = $e->getAwsRequestId();

        return new self($operation, $e->getAwsErrorCode(), $e->getStatusCode(), $requestId !== '' ? $requestId : null, $e::class);
    }
}
