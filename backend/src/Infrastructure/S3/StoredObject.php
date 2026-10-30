<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

/**
 * An object as one S3 read returned it: the body and the ETag of exactly those bytes, without the quotes S3 puts around
 * it. The ETag is an opaque identity of the stored bytes, not a security hash. A read whose answer has no ETag does not
 * make a StoredObject: the store throws instead.
 */
final readonly class StoredObject
{
    public function __construct(
        public string $body,
        public string $etag,
    ) {}
}
