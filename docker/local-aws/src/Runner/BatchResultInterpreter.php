<?php

declare(strict_types=1);

namespace LocalAws\Runner;

/**
 * Decides, from the function's answer, which messages of a batch succeeded. Mirrors how the Lambda event source mapping
 * treats a function that reports partial batch failures
 * (https://docs.aws.amazon.com/lambda/latest/dg/services-sqs-errorhandling.html#services-sqs-batchfailurereporting):
 *
 *  - a complete success: an empty or missing `batchItemFailures`, or a `null` response;
 *  - a complete failure: invalid JSON, an `itemIdentifier` that is missing, empty or not a message of the batch;
 *  - plus the local emulator's own signs of a failed invocation (ADR 0012, "Verified in Phase 0"): RIE answers a
 *    handler exception with HTTP 200 and `errorType` in the body, and no `X-Amz-Function-Error` header, so the body
 *    is what must be checked; any status other than 200 (for example 502 when BREF_RUNTIME is unset) fails too.
 *
 * A top-level JSON scalar or list (`true`, `42`, `"ok"`, `[1]`) is treated as a whole-batch failure: a conservative local
 * choice (Lambda's own handling of such responses is not documented; failing keeps the messages instead of losing them).
 *
 * The reason strings are for the log and never repeat the response body.
 */
final class BatchResultInterpreter
{
    /**
     * @param list<string> $batchMessageIds the `messageId`s that were sent, in the batch
     */
    public function interpret(array $batchMessageIds, InvocationResult $result): BatchVerdict
    {
        if ($result->status !== 200) {
            return BatchVerdict::wholeBatchFailed(sprintf('the function endpoint answered HTTP %d', $result->status));
        }
        try {
            $decoded = json_decode($result->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return BatchVerdict::wholeBatchFailed('the response is not valid JSON');
        }
        if ($decoded === null) {
            return BatchVerdict::partial([]);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return BatchVerdict::wholeBatchFailed('the response is not a JSON object');
        }
        if (array_key_exists('errorType', $decoded)) {
            return BatchVerdict::wholeBatchFailed('the invocation failed (errorType in the response)');
        }

        return $this->fromBatchItemFailures($batchMessageIds, $decoded['batchItemFailures'] ?? null);
    }

    /**
     * @param list<string> $batchMessageIds
     */
    private function fromBatchItemFailures(array $batchMessageIds, mixed $failures): BatchVerdict
    {
        if ($failures === null) {
            return BatchVerdict::partial([]);
        }
        if (!is_array($failures) || ($failures !== [] && !array_is_list($failures))) {
            return BatchVerdict::wholeBatchFailed('batchItemFailures is not a list');
        }

        $failedIds = [];
        foreach ($failures as $entry) {
            $identifier = is_array($entry) ? ($entry['itemIdentifier'] ?? null) : null;
            if (!is_string($identifier) || $identifier === '') {
                return BatchVerdict::wholeBatchFailed('a batchItemFailures entry has no usable itemIdentifier');
            }
            if (!in_array($identifier, $batchMessageIds, true)) {
                return BatchVerdict::wholeBatchFailed(sprintf('itemIdentifier "%s" is not a message of the batch', $identifier));
            }
            $failedIds[$identifier] = $identifier;
        }

        return BatchVerdict::partial(array_values($failedIds));
    }
}
