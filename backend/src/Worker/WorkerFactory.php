<?php

declare(strict_types=1);

namespace App\Worker;

use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\Config\InvalidConfiguration;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\S3ClientFactory;
use App\Infrastructure\S3\S3ObjectStore;
use App\Infrastructure\S3\S3OriginalObjects;
use App\Infrastructure\S3\S3ThumbnailObjects;
use App\Queue\SqsBatchProcessor;
use App\Receipts\Processing\FileInspector;
use App\Receipts\Processing\ReceiptProcessor;
use App\Receipts\Processing\ThumbnailMaker;
use App\Receipts\ReceiptRepository;
use App\Receipts\S3EventParser;

/**
 * Composition root of the receipt worker, like {@see \App\Api\ApiFactory} for the API. Knows nothing about Lambda or Bref
 * (ADR 0012): the same object runs under the Lambda entrypoint, the local Compose worker and the tests.
 *
 * The worker gets the two narrow ports (C13): originals can be read and deleted, thumbnails written and deleted; no
 * object of the receipts bucket can be written from here. The AWS clients are built here and nowhere else in the worker;
 * the SDK resolves credentials lazily, at the first call.
 */
final class WorkerFactory
{
    /**
     * @param array<array-key, mixed> $env the process environment
     * @param resource|null $logStream where the JSON log lines go; stderr when omitted
     *
     * @throws InvalidConfiguration the worker refuses to start without a valid configuration
     */
    public static function create(array $env, mixed $logStream = null): SqsBatchProcessor
    {
        $logger = new JsonLogger($logStream ?? JsonLogger::stderrStream());
        $config = WorkerConfig::fromEnvironment($env);
        $store = new S3ObjectStore(S3ClientFactory::forCalls($config->s3));

        $processor = new ReceiptProcessor(
            new S3EventParser(),
            new ReceiptRepository(new ItemStore(DynamoDbClientFactory::create($config->dynamoDb), $config->dynamoDb->tableName)),
            new S3OriginalObjects($store, $config->s3),
            new S3ThumbnailObjects($store, $config->s3),
            new FileInspector(),
            new ThumbnailMaker(),
            $logger,
            new SystemClock(),
            $config->s3,
        );

        return new SqsBatchProcessor($processor, $logger);
    }
}
