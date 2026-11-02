<?php

declare(strict_types=1);

namespace App\Tests\Support\S3;

use App\Infrastructure\Clock\Deadline;
use App\Infrastructure\S3\ObjectStore;
use App\Infrastructure\S3\ObjectStoreFailure;
use App\Infrastructure\S3\StoredObject;

/**
 * An ObjectStore in memory, with a switch to make every call fail the way an S3 outage does.
 */
final class InMemoryObjectStore implements ObjectStore
{
    /** @var array<string, array<string, string>> bucket => key => body */
    public array $objects = [];

    /** @var list<string> "operation bucket key" of every call, in order */
    public array $calls = [];

    public bool $failing = false;

    /** Makes only this operation (`get`, `put`, `delete`, `deletePrefix`) fail, the way an S3 outage on one call would. */
    public ?string $failOperation = null;

    public ?int $lastMaxBytes = null;

    /** The deadline given to the last deletePrefix(), to show that it is passed down. */
    public ?Deadline $lastDeadline = null;

    /** @var (\Closure(): void)|null run after each recorded call, e.g. to move a clock as a slow S3 would */
    public ?\Closure $afterCall = null;

    /** @var \ArrayObject<int, string>|null a list shared with other spies, so a test sees the ORDER of calls across them */
    public ?\ArrayObject $events = null;

    public function get(string $bucket, string $key, ?int $maxBytes = null): ?string
    {
        return $this->getObject($bucket, $key, $maxBytes)?->body;
    }

    public function getObject(string $bucket, string $key, ?int $maxBytes = null): ?StoredObject
    {
        $this->record('get', $bucket, $key);
        $this->lastMaxBytes = $maxBytes;
        $body = $this->objects[$bucket][$key] ?? null;
        if ($body !== null && $maxBytes !== null && strlen($body) > $maxBytes) {
            throw new ObjectStoreFailure('GetObject', ObjectStoreFailure::TOO_LARGE);
        }

        return $body === null ? null : new StoredObject($body, self::etagOf($body));
    }

    public function etag(string $bucket, string $key): ?string
    {
        $this->record('etag', $bucket, $key);
        $body = $this->objects[$bucket][$key] ?? null;

        return $body === null ? null : self::etagOf($body);
    }

    /** What S3 gives a single-part upload: the MD5 of the bytes. */
    public static function etagOf(string $body): string
    {
        return md5($body);
    }

    public function put(string $bucket, string $key, string $body, string $contentType): void
    {
        $this->record('put', $bucket, $key);
        $this->objects[$bucket][$key] = $body;
    }

    public function delete(string $bucket, string $key): void
    {
        $this->record('delete', $bucket, $key);
        unset($this->objects[$bucket][$key]);
    }

    public function deletePrefix(string $bucket, string $prefix, ?Deadline $deadline = null): int
    {
        $this->lastDeadline = $deadline;
        $this->record('deletePrefix', $bucket, $prefix);
        $removed = 0;
        foreach (array_keys($this->objects[$bucket] ?? []) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->objects[$bucket][$key]);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    public function keys(string $bucket): array
    {
        return array_keys($this->objects[$bucket] ?? []);
    }

    private function record(string $operation, string $bucket, string $key): void
    {
        $this->calls[] = "{$operation} {$bucket} {$key}";
        $this->events?->append("s3 {$operation} {$bucket} {$key}");
        if ($this->failing || $this->failOperation === $operation) {
            throw new ObjectStoreFailure($operation, 'AccessDenied');
        }
        if ($this->afterCall !== null) {
            ($this->afterCall)();
        }
    }
}
