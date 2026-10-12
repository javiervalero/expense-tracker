<?php

declare(strict_types=1);

namespace App\Receipts;

/**
 * An object in a bucket, as named by an S3 event: the bucket and the decoded key (the real key, not the
 * percent-encoded form that event notifications carry).
 */
final readonly class S3ObjectRef
{
    public function __construct(public string $bucket, public string $key) {}
}
