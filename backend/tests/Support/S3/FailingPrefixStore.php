<?php

declare(strict_types=1);

namespace App\Tests\Support\S3;

use App\Infrastructure\Clock\Deadline;
use App\Infrastructure\S3\ObjectStore;
use App\Infrastructure\S3\ObjectStoreFailure;

/**
 * An ObjectStore whose deletePrefix() always throws the failure it was given (for example `PageLimitReached` or
 * `DeadlineReached`, which only the real store can produce after paging). It records each folder it was asked for.
 */
final class FailingPrefixStore implements ObjectStore
{
    /** @var list<string> "bucket prefix" of every deletePrefix call, in order */
    public array $prefixCalls = [];

    public function __construct(private readonly ObjectStoreFailure $failure) {}

    public function get(string $bucket, string $key, ?int $maxBytes = null): ?string
    {
        return null;
    }

    public function put(string $bucket, string $key, string $body, string $contentType): void {}

    public function delete(string $bucket, string $key): void {}

    public function deletePrefix(string $bucket, string $prefix, ?Deadline $deadline = null): int
    {
        $this->prefixCalls[] = $bucket . ' ' . $prefix;

        throw $this->failure;
    }
}
