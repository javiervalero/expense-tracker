<?php

declare(strict_types=1);

/*
 * One-shot bootstrap of the local S3 and DynamoDB stand-ins (service `local-aws-init` in docker-compose.yml).
 * Safe to run any number of times: it only changes what differs and logs "no changes" otherwise.
 * Exit code 0 on success, 1 on any failure (the failure is logged as a JSON line).
 */

use LocalAws\Bootstrap\BootstrapSettings;
use LocalAws\Bootstrap\DynamoDbBootstrapper;
use LocalAws\Bootstrap\DynamoDbClientFactory;
use LocalAws\Bootstrap\S3Bootstrapper;
use LocalAws\Bootstrap\S3ClientFactory;
use LocalAws\Bootstrap\ServiceWaiter;
use LocalAws\Bootstrap\TableSchema;
use LocalAws\Logging\JsonLogger;

require __DIR__ . '/../vendor/autoload.php';

$logger = new JsonLogger(
    static function (string $line): void {
        fwrite(STDOUT, $line);
    },
    null,
    array_values(array_filter([getenv('AWS_SECRET_ACCESS_KEY'), getenv('S3_WEBHOOK_AUTH_TOKEN')], is_string(...))),
);

try {
    $environment = array_filter(getenv(), is_string(...));
    $settings = BootstrapSettings::fromEnvironment($environment);

    $s3 = S3ClientFactory::create($settings);

    ServiceWaiter::withSystemClock($logger)->waitFor(
        's3',
        static function () use ($s3): void {
            $s3->listBuckets();
        },
        $settings->waitTimeoutSeconds,
    );
    (new S3Bootstrapper($s3, $logger, $settings->corsAllowedOrigin, $settings->notificationArn))->run();

    $dynamoDb = DynamoDbClientFactory::create($settings);
    ServiceWaiter::withSystemClock($logger)->waitFor(
        'dynamodb',
        static function () use ($dynamoDb): void {
            $dynamoDb->listTables(['Limit' => 1]);
        },
        $settings->waitTimeoutSeconds,
    );
    (new DynamoDbBootstrapper($dynamoDb, $logger, TableSchema::fromFile(__DIR__ . '/../config/dynamodb-table.json'), $settings->dynamoDbTable))->run();
} catch (Throwable $failure) {
    $logger->error('bootstrap failed', ['error' => $failure->getMessage(), 'type' => $failure::class]);
    exit(1);
}
