<?php

declare(strict_types=1);

use Bref\Context\Context;

// Function-mode handler (BREF_RUNTIME=function). Mimics the SQS worker contract:
// a record whose body is "fail" is reported in batchItemFailures; "throw" raises.
return static function (array $event, Context $context): array {
    $failures = [];
    foreach ($event['Records'] ?? [] as $record) {
        $body = (string) ($record['body'] ?? '');
        if ($body === 'throw') {
            throw new RuntimeException('handler blew up on ' . ($record['messageId'] ?? '?'));
        }
        if ($body === 'fail') {
            $failures[] = ['itemIdentifier' => (string) $record['messageId']];
        }
    }

    return ['batchItemFailures' => $failures];
};
