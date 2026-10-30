<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use App\Infrastructure\Clock\Deadline;

/**
 * The four things the application does with objects. Every method may throw {@see ObjectStoreFailure}.
 */
interface ObjectStore
{
    /**
     * @param int|null $maxBytes refuse (ObjectStoreFailure with code `TooLarge`) an object bigger than this; never reads
     *                           more than $maxBytes + 1 bytes of the body, whatever its headers say
     *
     * @return string|null the body, or null when there is no such key
     */
    public function get(string $bucket, string $key, ?int $maxBytes = null): ?string;

    public function put(string $bucket, string $key, string $body, string $contentType): void;

    /** Deleting a key that is not there succeeds, as on S3. */
    public function delete(string $bucket, string $key): void;

    /**
     * Deletes the objects under a folder. It goes through the pages even when some keys cannot be deleted, then throws
     * once. It handles at most a few pages (3000 keys) and stops before a page once the deadline has passed, so one
     * request cannot run for long. If objects may remain it throws an ObjectStoreFailure whose code lists, comma
     * separated, S3's per-key error codes and then `PageLimitReached` or `DeadlineReached` (the leftover is the caller's
     * to report).
     *
     * @param string $prefix a folder of at least two non-empty segments ending with `/`, such as `u/e1/` (so `e1/`
     *                       never touches `e10/`); anything else is refused
     *
     * @param Deadline|null $deadline null: only the page limit applies
     *
     * @return int how many objects were deleted
     *
     * @throws \InvalidArgumentException when the prefix is not such a folder
     */
    public function deletePrefix(string $bucket, string $prefix, ?Deadline $deadline = null): int;
}
