<?php

declare(strict_types=1);

namespace App\Api;

use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Id\IdGenerator;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\S3Settings;

/**
 * What the route handlers share, built once per request by {@see ApiFactory}: php-fpm keeps no state between
 * requests, so nothing is reused, and building is cheap because the SDK resolves credentials lazily. (Real reuse would
 * need `BREF_RUNTIME=function`, a change to ADR 0012.)
 *
 * The S3 settings are plain values; the S3 clients are NOT built here but by the one route that needs them, so a request
 * to any other route builds no S3 client.
 */
final readonly class ApiServices
{
    public function __construct(
        public ItemStore $items,
        public Clock $clock,
        public IdGenerator $ids,
        public JsonLogger $logger,
        public S3Settings $s3,
    ) {}
}
