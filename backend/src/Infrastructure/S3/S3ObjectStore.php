<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use App\Infrastructure\Clock\Deadline;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * {@see ObjectStore} over S3. Takes the CALLS or CLEANUP client ({@see S3ClientFactory::forCalls()}, {@see S3ClientFactory::forCleanup()}).
 * The SDK retries a failing call (3 attempts in total on the first, 2 on the second); what still fails becomes an
 * {@see ObjectStoreFailure} without the SDK's message.
 */
final readonly class S3ObjectStore implements ObjectStore
{
    /** What S3 returns per ListObjectsV2 page, and accepts per DeleteObjects call. */
    private const PAGE_SIZE = 1000;
    private const MIN_PREFIX_SEGMENTS = 2;
    private const NOT_FOUND = 404;
    /**
     * Pages of a folder that one call handles (3000 keys). An expense has one receipt and a few strays, so this is far
     * above real use, and it bounds the time of a request (each S3 call may take 20 s x 3 attempts on the general client, 5 s
     * x 2 attempts on the cleanup client; the caller's deadline is the tighter bound). What is left is
     * reported as a failure, which {@see \App\Receipts\ReceiptObjectCleaner} logs with the folder (C9).
     */
    private const MAX_PAGES = 3;
    private const PAGE_LIMIT_REACHED = 'PageLimitReached';
    private const DEADLINE_REACHED = 'DeadlineReached';

    public function __construct(private S3Client $client) {}

    public function get(string $bucket, string $key, ?int $maxBytes = null): ?string
    {
        return $this->read($bucket, $key, $maxBytes)[0] ?? null;
    }

    public function getObject(string $bucket, string $key, ?int $maxBytes = null): ?StoredObject
    {
        $read = $this->read($bucket, $key, $maxBytes);
        if ($read === null) {
            return null;
        }
        if ($read[1] === null) {
            // The caller compares ETags later: an answer without one is retried, never stored as "no ETag".
            throw new ObjectStoreFailure('GetObject', 'UnexpectedResponse');
        }

        return new StoredObject($read[0], $read[1]);
    }

    /**
     * @return array{string, string|null}|null the body and the ETag (null when the answer had none), or null when the key is gone
     */
    private function read(string $bucket, string $key, ?int $maxBytes): ?array
    {
        try {
            // stream: the body is not downloaded until it is read, so the size can be checked first.
            // decode_content false: Guzzle would otherwise inflate a gzip/deflate body (a decompression bomb) and
            // drop its Content-Length; the bytes are read exactly as S3 sends them.
            $result = $this->client->getObject(['Bucket' => $bucket, 'Key' => $key, '@http' => ['stream' => true, 'decode_content' => false]]);
        } catch (\RuntimeException $e) {
            if ($e instanceof AwsException && $e->getAwsErrorCode() === 'NoSuchKey') {
                return null;
            }

            throw ObjectStoreFailure::fromSdk('GetObject', $e);
        }
        $body = $result['Body'] ?? null;
        if (!$body instanceof StreamInterface) {
            throw new ObjectStoreFailure('GetObject', 'UnexpectedResponse');
        }
        try {
            $content = $this->readBody($body, $result['ContentLength'] ?? null, $maxBytes);
        } finally {
            $body->close();
        }

        return [$content, self::normalisedEtag($result['ETag'] ?? null)];
    }

    public function etag(string $bucket, string $key): ?string
    {
        try {
            $result = $this->client->headObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (\RuntimeException $e) {
            // A HEAD has no body, so S3 answers a missing key with 404 and the code NotFound rather than NoSuchKey.
            if ($e instanceof AwsException && ($e->getStatusCode() === self::NOT_FOUND || $e->getAwsErrorCode() === 'NoSuchKey')) {
                return null;
            }

            throw ObjectStoreFailure::fromSdk('HeadObject', $e);
        }
        $etag = self::normalisedEtag($result['ETag'] ?? null);
        if ($etag === null) {
            throw new ObjectStoreFailure('HeadObject', 'UnexpectedResponse');
        }

        return $etag;
    }

    /** S3 sends the ETag in quotes; everything above this class compares the bare value. */
    private static function normalisedEtag(mixed $etag): ?string
    {
        $bare = is_string($etag) ? trim($etag, '"') : '';

        return $bare === '' ? null : $bare;
    }

    /**
     * Never reads more than one byte past the limit, whatever the stream holds.
     */
    private function readBody(StreamInterface $body, mixed $length, ?int $maxBytes): string
    {
        if ($maxBytes !== null && is_int($length) && $length > $maxBytes) {
            throw new ObjectStoreFailure('GetObject', ObjectStoreFailure::TOO_LARGE);
        }
        try {
            $content = $maxBytes === null ? $body->getContents() : Utils::copyToString($body, $maxBytes + 1);
        } catch (\RuntimeException $e) {
            throw ObjectStoreFailure::fromSdk('GetObject', $e);
        }
        if ($maxBytes !== null && strlen($content) > $maxBytes) {
            throw new ObjectStoreFailure('GetObject', ObjectStoreFailure::TOO_LARGE);
        }

        return $content;
    }

    public function put(string $bucket, string $key, string $body, string $contentType): void
    {
        try {
            $this->client->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => $body, 'ContentType' => $contentType]);
        } catch (\RuntimeException $e) {
            throw ObjectStoreFailure::fromSdk('PutObject', $e);
        }
    }

    public function delete(string $bucket, string $key): void
    {
        try {
            $this->client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (\RuntimeException $e) {
            throw ObjectStoreFailure::fromSdk('DeleteObject', $e);
        }
    }

    public function deletePrefix(string $bucket, string $prefix, ?Deadline $deadline = null): int
    {
        self::assertFolder($prefix);
        $deleted = 0;
        $errorCodes = [];
        $errorCount = 0;
        $token = null;
        $pages = 0;
        $stoppedBy = null;
        do {
            if ($deadline?->hasPassed() === true) {
                $stoppedBy = self::DEADLINE_REACHED;
                break;
            }
            $keys = [];
            try {
                $page = $this->client->listObjectsV2(
                    ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => self::PAGE_SIZE] + ($token !== null ? ['ContinuationToken' => $token] : []),
                );
            } catch (\RuntimeException $e) {
                throw ObjectStoreFailure::fromSdk('ListObjectsV2', $e);
            }
            foreach ((array) ($page['Contents'] ?? []) as $object) {
                if (is_array($object) && is_string($object['Key'] ?? null)) {
                    $keys[] = ['Key' => $object['Key']];
                }
            }
            // S3 answers MalformedXML to DeleteObjects with an empty list (Step 0 R5): an empty page sends nothing.
            if ($keys !== []) {
                // The listing may have been slow: check again, so the worst case is the budget plus one S3 call.
                if ($deadline?->hasPassed() === true) {
                    $stoppedBy = self::DEADLINE_REACHED;
                    break;
                }
                $codes = $this->deleteKeys($bucket, $keys);
                $deleted += count($keys) - count($codes);
                $errorCount += count($codes);
                $errorCodes = [...$errorCodes, ...$codes];
            }
            ++$pages;
            $token = $this->nextToken($page);
            if ($token !== null && $pages >= self::MAX_PAGES) {
                // More pages exist: stop instead of paging without end, and report the leftover below.
                $stoppedBy = self::PAGE_LIMIT_REACHED;
                break;
            }
        } while ($token !== null);
        if ($errorCount > 0 || $stoppedBy !== null) {
            // After the last page handled, so one bad key does not leave the rest of the folder behind. Both causes are
            // reported: per-key errors first, then why the listing stopped early.
            $codes = $stoppedBy === null ? $errorCodes : [...$errorCodes, $stoppedBy];

            throw new ObjectStoreFailure($errorCount > 0 ? 'DeleteObjects' : 'ListObjectsV2', implode(',', array_values(array_unique($codes))), null, null, null, max($errorCount, 1));
        }

        return $deleted;
    }

    /**
     * A folder: at least two non-empty segments, each followed by `/` (no leading slash, no `//`).
     */
    private static function assertFolder(string $prefix): void
    {
        $segments = explode('/', $prefix);
        $last = array_pop($segments);
        $isFolder = $last === '' && count($segments) >= self::MIN_PREFIX_SEGMENTS && !in_array('', $segments, true);
        if (!$isFolder) {
            throw new \InvalidArgumentException('A prefix to delete must be a folder of at least two non-empty segments, ending with "/".');
        }
    }

    /**
     * @param list<array{Key: string}> $keys
     *
     * @return list<string> the error code of each key that S3 could not delete (empty when all went)
     */
    private function deleteKeys(string $bucket, array $keys): array
    {
        try {
            $result = $this->client->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $keys, 'Quiet' => true]]);
        } catch (\RuntimeException $e) {
            throw ObjectStoreFailure::fromSdk('DeleteObjects', $e);
        }
        // A 200 can still carry per-key errors: a leftover must not pass as a success.
        $codes = [];
        foreach ((array) ($result['Errors'] ?? []) as $error) {
            $codes[] = is_array($error) && is_string($error['Code'] ?? null) ? $error['Code'] : 'Unknown';
        }

        return $codes;
    }

    /**
     * @param \ArrayAccess<string, mixed> $page
     */
    private function nextToken(\ArrayAccess $page): ?string
    {
        if (($page['IsTruncated'] ?? false) !== true) {
            return null;
        }
        $token = $page['NextContinuationToken'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new ObjectStoreFailure('ListObjectsV2', 'MissingContinuationToken');
        }

        return $token;
    }
}
