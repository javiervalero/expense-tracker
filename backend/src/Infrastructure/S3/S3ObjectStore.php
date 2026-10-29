<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * {@see ObjectStore} over S3. Takes the CALLS client ({@see S3ClientFactory::forCalls()}). The SDK retries a failing
 * call (3 attempts in total); what still fails becomes an {@see ObjectStoreFailure} without the SDK's message.
 */
final readonly class S3ObjectStore implements ObjectStore
{
    /** What S3 returns per ListObjectsV2 page, and accepts per DeleteObjects call. */
    private const PAGE_SIZE = 1000;
    private const MIN_PREFIX_SEGMENTS = 2;

    public function __construct(private S3Client $client) {}

    public function get(string $bucket, string $key, ?int $maxBytes = null): ?string
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
            return $this->readBody($body, $result['ContentLength'] ?? null, $maxBytes);
        } finally {
            $body->close();
        }
    }

    /**
     * Never reads more than one byte past the limit, whatever the stream holds.
     */
    private function readBody(StreamInterface $body, mixed $length, ?int $maxBytes): string
    {
        if ($maxBytes !== null && is_int($length) && $length > $maxBytes) {
            throw new ObjectStoreFailure('GetObject', 'TooLarge');
        }
        try {
            $content = $maxBytes === null ? $body->getContents() : Utils::copyToString($body, $maxBytes + 1);
        } catch (\RuntimeException $e) {
            throw ObjectStoreFailure::fromSdk('GetObject', $e);
        }
        if ($maxBytes !== null && strlen($content) > $maxBytes) {
            throw new ObjectStoreFailure('GetObject', 'TooLarge');
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

    public function deletePrefix(string $bucket, string $prefix): int
    {
        self::assertFolder($prefix);
        $deleted = 0;
        $errorCodes = [];
        $errorCount = 0;
        $token = null;
        do {
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
                $codes = $this->deleteKeys($bucket, $keys);
                $deleted += count($keys) - count($codes);
                $errorCount += count($codes);
                $errorCodes = [...$errorCodes, ...$codes];
            }
            $token = $this->nextToken($page);
        } while ($token !== null);
        if ($errorCount > 0) {
            // After the last page, so one bad key does not leave the rest of the folder behind.
            throw new ObjectStoreFailure('DeleteObjects', implode(',', array_values(array_unique($errorCodes))), null, null, null, $errorCount);
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
