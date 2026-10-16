<?php

declare(strict_types=1);

namespace App\Api;

use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Id\IdGenerator;
use App\Infrastructure\Logging\JsonLogger;

/**
 * What the route handlers share, built once per process by {@see ApiFactory} (a warm Lambda reuses the DynamoDB
 * client and its connections across invocations).
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
