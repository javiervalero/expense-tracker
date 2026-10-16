<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

/**
 * One page of a query. A non-null `lastEvaluatedKey` means there may be more: pass it back as `exclusiveStartKey`.
 */
final readonly class QueryPage
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>|null $lastEvaluatedKey
     */
    public function __construct(public array $items, public ?array $lastEvaluatedKey) {}
}
