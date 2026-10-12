<?php

declare(strict_types=1);

namespace LocalAws\Bootstrap;

use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use LocalAws\Logging\JsonLogger;

/**
 * Brings the S3 stand-in to the state the application expects (ADR 0006, ADR 0007), changing only what differs:
 *
 *  - buckets `receipts` and `receipt-thumbnails` exist;
 *  - `receipts` allows browser uploads and downloads from the Vite origin (CORS);
 *  - `receipts` sends an ObjectCreated notification to the relay for keys under `receipts/`, and nothing else
 *    does (the thumbnails bucket never gets a rule, which would otherwise loop the worker on its own output).
 *
 * Every step reads the current state first and writes only on a difference, so a second run does no writes and
 * says "no changes". The notification rule is managed only when a target ARN is given.
 */
final readonly class S3Bootstrapper
{
    public const RECEIPTS_BUCKET = 'receipts';
    public const THUMBNAILS_BUCKET = 'receipt-thumbnails';
    private const RECEIPTS_KEY_PREFIX = 'receipts/';
    private const NOTIFICATION_ID = 'receipts-object-created';
    private const CREATED_EVENTS = 's3:ObjectCreated:*';
    private const CORS_MAX_AGE_SECONDS = 600;
    private const CORS_METHODS = ['POST', 'GET', 'HEAD'];
    private const CORS_EXPOSED_HEADERS = ['ETag'];

    public function __construct(
        private S3Client $s3,
        private JsonLogger $logger,
        private string $corsAllowedOrigin,
        private ?string $notificationArn,
    ) {}

    /**
     * @return int the number of changes made (0 when everything already matched)
     *
     * @throws AwsException on any unexpected answer from the emulator
     */
    public function run(): int
    {
        $changes = (int) $this->ensureBucket(self::RECEIPTS_BUCKET)
            + (int) $this->ensureBucket(self::THUMBNAILS_BUCKET)
            + (int) $this->ensureCors()
            + (int) $this->ensureNotification();

        if ($changes === 0) {
            $this->logger->info('no changes');
        } else {
            $this->logger->info('bootstrap finished', ['changes' => $changes]);
        }

        return $changes;
    }

    private function ensureBucket(string $bucket): bool
    {
        try {
            $this->s3->headBucket(['Bucket' => $bucket]);

            return false;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e; // 403 and friends: the bucket exists but is not ours to use, so never "create" it.
            }
        }

        try {
            $this->s3->createBucket(['Bucket' => $bucket]);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'BucketAlreadyOwnedByYou') {
                return false; // created by someone else between the check and the create: nothing to do
            }
            throw $e;
        }
        $this->logger->info('created bucket', ['bucket' => $bucket]);

        return true;
    }

    private function ensureCors(): bool
    {
        $desired = [
            'AllowedOrigins' => [$this->corsAllowedOrigin],
            'AllowedMethods' => self::CORS_METHODS,
            'AllowedHeaders' => ['*'],
            'ExposeHeaders' => self::CORS_EXPOSED_HEADERS,
            'MaxAgeSeconds' => self::CORS_MAX_AGE_SECONDS,
        ];

        if ($this->currentCorsRules() === [self::normaliseCorsRule($desired)]) {
            return false;
        }

        $this->s3->putBucketCors(['Bucket' => self::RECEIPTS_BUCKET, 'CORSConfiguration' => ['CORSRules' => [$desired]]]);
        $this->logger->info('set bucket CORS', ['bucket' => self::RECEIPTS_BUCKET, 'origin' => $this->corsAllowedOrigin]);

        return true;
    }

    /**
     * @return list<array<string, mixed>> normalised rules, empty when the bucket has no CORS configuration
     */
    private function currentCorsRules(): array
    {
        try {
            $rules = $this->s3->getBucketCors(['Bucket' => self::RECEIPTS_BUCKET])['CORSRules'];
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchCORSConfiguration') {
                return [];
            }
            throw $e;
        }

        return array_map(self::normaliseCorsRule(...), self::arrayList($rules));
    }

    /**
     * Keeps only what we set, with lists sorted, so that field order and extras added by the emulator do not
     * look like a difference.
     *
     * @param array<array-key, mixed> $rule
     *
     * @return array<string, mixed>
     */
    private static function normaliseCorsRule(array $rule): array
    {
        $methods = array_map(strtoupper(...), self::stringList($rule['AllowedMethods'] ?? []));
        $maxAge = $rule['MaxAgeSeconds'] ?? null;

        return [
            'AllowedOrigins' => self::sorted(self::stringList($rule['AllowedOrigins'] ?? [])),
            'AllowedMethods' => self::sorted($methods),
            'AllowedHeaders' => self::sorted(self::stringList($rule['AllowedHeaders'] ?? [])),
            'ExposeHeaders' => self::sorted(self::stringList($rule['ExposeHeaders'] ?? [])),
            'MaxAgeSeconds' => is_numeric($maxAge) ? (int) $maxAge : null,
        ];
    }

    private function ensureNotification(): bool
    {
        if ($this->notificationArn === null) {
            $this->logger->info('notification rule not managed: no webhook target configured (S3_NOTIFICATION_ARN is empty)');

            return false;
        }

        $desired = [
            'Id' => self::NOTIFICATION_ID,
            'QueueArn' => $this->notificationArn,
            'Events' => [self::CREATED_EVENTS],
            'Filter' => ['Key' => ['FilterRules' => [['Name' => 'prefix', 'Value' => self::RECEIPTS_KEY_PREFIX]]]],
        ];

        if ($this->currentQueueRules() === [self::normaliseQueueRule($desired)]) {
            return false;
        }

        $this->s3->putBucketNotificationConfiguration([
            'Bucket' => self::RECEIPTS_BUCKET,
            'NotificationConfiguration' => ['QueueConfigurations' => [$desired]],
        ]);
        $this->logger->info('set bucket notification', [
            'bucket' => self::RECEIPTS_BUCKET,
            'target' => $this->notificationArn,
            'prefix' => self::RECEIPTS_KEY_PREFIX,
        ]);

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentQueueRules(): array
    {
        $rules = $this->s3->getBucketNotificationConfiguration(['Bucket' => self::RECEIPTS_BUCKET])['QueueConfigurations'];

        return array_map(self::normaliseQueueRule(...), self::arrayList($rules));
    }

    /**
     * The rule's Id is ignored (the emulator may rename it); the target, the events and the prefix are what count.
     *
     * @param array<array-key, mixed> $rule
     *
     * @return array<string, mixed>
     */
    private static function normaliseQueueRule(array $rule): array
    {
        $arn = $rule['QueueArn'] ?? null;

        return [
            'QueueArn' => is_string($arn) ? $arn : null,
            'Events' => self::sorted(self::stringList($rule['Events'] ?? [])),
            'Prefix' => self::prefixOf($rule['Filter'] ?? null),
        ];
    }

    private static function prefixOf(mixed $filter): ?string
    {
        $key = is_array($filter) ? ($filter['Key'] ?? null) : null;
        $filterRules = is_array($key) ? ($key['FilterRules'] ?? null) : null;
        foreach (self::arrayList($filterRules) as $filterRule) {
            $name = $filterRule['Name'] ?? null;
            $value = $filterRule['Value'] ?? null;
            if (is_string($name) && strtolower($name) === 'prefix' && is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<array<array-key, mixed>> the array items of a list, dropping anything else
     */
    private static function arrayList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
