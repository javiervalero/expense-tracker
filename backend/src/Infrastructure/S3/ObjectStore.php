<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

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
     * Deletes every object under a folder. It goes through all pages even when some keys cannot be deleted, then
     * throws once.
     *
     * @param string $prefix a folder of at least two non-empty segments ending with `/`, such as `u/e1/` (so `e1/`
     *                       never touches `e10/`); anything else is refused
     *
     * @return int how many objects were deleted
     *
     * @throws \InvalidArgumentException when the prefix is not such a folder
     */
    public function deletePrefix(string $bucket, string $prefix): int;
}
