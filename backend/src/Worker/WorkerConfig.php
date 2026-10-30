<?php

declare(strict_types=1);

namespace App\Worker;

use App\Infrastructure\Config\InvalidConfiguration;
use App\Infrastructure\Config\LocalEnvironment;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\S3\S3Settings;

/**
 * The worker's configuration, read once from the process environment by {@see WorkerFactory}, with the same settings
 * classes and the same rules as the API's ({@see \App\Infrastructure\Config\ApiConfig}): the table and both buckets are
 * required, an endpoint (only for the local stand-ins) must be a local one, with `APP_ENV=local` the endpoints are
 * mandatory and in any other environment they are refused ({@see LocalEnvironment}). Every problem is collected before failing, and only variable names are reported, never values.
 *
 * The worker only calls S3 (never signs), but {@see S3Settings} keeps the two S3 endpoints together, so locally
 * `S3_PUBLIC_ENDPOINT` is set next to `S3_ENDPOINT` and is not used.
 */
final readonly class WorkerConfig
{
    private function __construct(
        public DynamoDbSettings $dynamoDb,
        public S3Settings $s3,
    ) {}

    /**
     * @param array<array-key, mixed> $env
     *
     * @throws InvalidConfiguration
     */
    public static function fromEnvironment(array $env): self
    {
        $errors = [];
        $dynamoDb = InvalidConfiguration::collect(static fn(): DynamoDbSettings => DynamoDbSettings::fromEnvironment($env), $errors);
        $s3 = InvalidConfiguration::collect(static fn(): S3Settings => S3Settings::fromEnvironment($env), $errors);
        LocalEnvironment::checkEndpoints($env, $errors);
        $errors = array_values(array_unique($errors)); // AWS_REGION is read by both settings classes
        sort($errors);
        if ($errors !== [] || $dynamoDb === null || $s3 === null) {
            throw new InvalidConfiguration($errors);
        }

        return new self($dynamoDb, $s3);
    }
}
