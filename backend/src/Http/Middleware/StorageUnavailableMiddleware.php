<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\Logging\JsonLogger;

/**
 * Turns DynamoDB overload into `503` with `Retry-After: 1` (A18). This only sees failures after the SDK has made its
 * own attempts. Overload is: a {@see StorageFailure} with a throttling code, or a {@see TransactionCancelled} whose
 * reasons are only throttling, `TransactionConflict` or `None` (and at least one that is not `None`). A
 * `ConditionalCheckFailed` is a domain result and a `ValidationError` is a bug, so those, and every other throwable,
 * are rethrown unchanged (the error middleware makes them a generic 500).
 *
 * The log line holds the operation, the code and the AWS request id: never item data.
 */
final readonly class StorageUnavailableMiddleware implements Middleware
{
    private const RETRY_AFTER_SECONDS = '1';
    private const THROTTLING_CODES = ['ProvisionedThroughputExceededException', 'ThrottlingException', 'RequestLimitExceeded'];
    private const OVERLOAD_REASONS = ['ThrottlingError', 'ProvisionedThroughputExceeded', 'TransactionConflict'];
    private const NO_REASON = 'None';

    public function __construct(private JsonLogger $logger) {}

    public function process(Request $request, Handler $next): Response
    {
        try {
            return $next->handle($request);
        } catch (StorageFailure $failure) {
            if ($failure->awsErrorCode === null || !in_array($failure->awsErrorCode, self::THROTTLING_CODES, true)) {
                throw $failure;
            }
            $this->unavailable($request, $failure->operation, $failure->awsErrorCode, $failure->awsRequestId);
        } catch (TransactionCancelled $cancelled) {
            if (!self::isOverload($cancelled->reasons)) {
                throw $cancelled;
            }
            $this->unavailable($request, 'TransactWriteItems', implode(',', $cancelled->reasons), $cancelled->awsRequestId);
        }
    }

    /**
     * @param list<string> $reasons
     */
    private static function isOverload(array $reasons): bool
    {
        $meaningful = array_filter($reasons, static fn(string $reason): bool => $reason !== self::NO_REASON);

        return $meaningful !== [] && array_diff($meaningful, self::OVERLOAD_REASONS) === [];
    }

    private function unavailable(Request $request, string $operation, string $code, ?string $awsRequestId): never
    {
        $this->logger->warning('storage unavailable', [
            'requestId' => $request->attribute(Request::REQUEST_ID),
            'operation' => $operation,
            'code' => $code,
            'awsRequestId' => $awsRequestId,
        ]);

        throw HttpProblem::of(503, headers: ['Retry-After' => [self::RETRY_AFTER_SECONDS]]);
    }
}
