<?php

declare(strict_types=1);

namespace App\Api;

use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Id\IdGenerator;
use App\Infrastructure\Logging\JsonLogger;

/**
 * What the route handlers share, built once per request by {@see ApiFactory}: php-fpm keeps no state between
 * requests, so nothing is reused, and building is cheap because the SDK resolves credentials lazily. (Real reuse would
 * need `BREF_RUNTIME=function`, a change to ADR 0012.)
 */
final readonly class ApiServices
{
    public function __construct(
        public ItemStore $items,
        public Clock $clock,
        public IdGenerator $ids,
        public JsonLogger $logger,
    ) {}
}
