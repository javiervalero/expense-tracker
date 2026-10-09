<?php

declare(strict_types=1);

/*
 * Idempotent bootstrap for the S3 emulator spike.
 *
 * Creates the two buckets and attaches the webhook notification rule to `receipts` (prefix `receipts/`) using only
 * the AWS SDK (no mcli). Safe to run any number of times. Used both as a CLI script and as a library by the tests.
 */

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

require_once __DIR__ . '/vendor/autoload.php';

const BUCKET_RECEIPTS = 'receipts';
const BUCKET_THUMBNAILS = 'receipt-thumbnails';

function env_or(string $name, string $default): string
{
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
}

function s3_client(?string $endpoint = null): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region' => env_or('S3_REGION', 'us-east-1'),
        'endpoint' => $endpoint ?? env_or('S3_ENDPOINT', 'http://s3:9000'),
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => env_or('S3_ACCESS_KEY', 'spikeaccesskey'),
            'secret' => env_or('S3_SECRET_KEY', 'spikesecretkey1234'),
        ],
    ]);
}

function wait_until_live(S3Client $client, int $timeoutSeconds = 60): void
{
    $deadline = time() + $timeoutSeconds;
    do {
        try {
            $client->listBuckets();

            return;
        } catch (Throwable) {
            usleep(500_000);
        }
    } while (time() < $deadline);

    throw new RuntimeException('S3 endpoint did not become ready in time');
}

function ensure_bucket(S3Client $client, string $bucket): void
{
    try {
        $client->headBucket(['Bucket' => $bucket]);

        return;
    } catch (S3Exception) {
        // not there yet: create below
    }

    try {
        $client->createBucket(['Bucket' => $bucket]);
    } catch (S3Exception $e) {
        if (!in_array($e->getAwsErrorCode(), ['BucketAlreadyOwnedByYou', 'BucketAlreadyExists'], true)) {
            throw $e;
        }
    }
}

function ensure_receipts_notification(S3Client $client): void
{
    $client->putBucketNotificationConfiguration([
        'Bucket' => BUCKET_RECEIPTS,
        'NotificationConfiguration' => [
            'QueueConfigurations' => [[
                'Id' => 'receipts-created',
                'QueueArn' => env_or('WEBHOOK_ARN', 'arn:minio:sqs::SPIKE:webhook'),
                'Events' => ['s3:ObjectCreated:*'],
                'Filter' => ['Key' => ['FilterRules' => [['Name' => 'prefix', 'Value' => 'receipts/']]]],
            ]],
        ],
    ]);
}

function bootstrap_s3(S3Client $client): void
{
    wait_until_live($client);
    ensure_bucket($client, BUCKET_RECEIPTS);
    ensure_bucket($client, BUCKET_THUMBNAILS);
    ensure_receipts_notification($client);
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        bootstrap_s3(s3_client());
        fwrite(STDOUT, "bootstrap ok\n");
    } catch (Throwable $e) {
        fwrite(STDERR, 'bootstrap failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
