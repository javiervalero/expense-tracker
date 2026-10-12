<?php

declare(strict_types=1);

namespace App\Worker;

use App\Infrastructure\Logging\JsonLogger;
use App\Queue\SqsBatchProcessor;
use App\Receipts\ReceivedReceiptLogger;
use App\Receipts\S3EventParser;

/**
 * Composition root of the receipt worker. Knows nothing about Lambda or Bref (ADR 0012): the same object runs
 * under the Lambda entrypoint, the local Compose worker and the tests. Phase 5 swaps the placeholder processor
 * for the real receipt processor here, and nowhere else.
 */
final class WorkerFactory
{
    /**
     * @param resource|null $logStream where the JSON log lines go; stderr when omitted
     */
    public static function create(mixed $logStream = null): SqsBatchProcessor
    {
        $logger = new JsonLogger($logStream ?? self::stderr());

        return new SqsBatchProcessor(new ReceivedReceiptLogger(new S3EventParser(), $logger), $logger);
    }

    /**
     * @return resource
     */
    private static function stderr(): mixed
    {
        $stream = fopen('php://stderr', 'wb');
        // @codeCoverageIgnoreStart
        // Unreachable in tests: php://stderr cannot be made to fail to open.
        if ($stream === false) {
            throw new \RuntimeException('Cannot open php://stderr for logging.');
        }
        // @codeCoverageIgnoreEnd

        return $stream;
    }
}
