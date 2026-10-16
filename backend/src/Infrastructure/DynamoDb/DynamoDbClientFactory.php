<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;

/**
 * The one place a DynamoDB client is built. Short timeouts and bounded retries keep a slow table from eating the
 * Lambda's time. No credentials are passed: the SDK's default chain finds them (the execution role on Lambda).
 *
 * Outside Lambda (where the runtime sets `AWS_REGION`) a region must come from `AWS_REGION` in the settings or from
 * the SDK's own chain (shared config file, `AWS_DEFAULT_REGION`); with none, the first call fails and the store
 * reports it as a `StorageFailure`.
 */
final class DynamoDbClientFactory
{
    private const API_VERSION = '2012-08-10';
    private const CONNECT_TIMEOUT_SECONDS = 1;
    private const REQUEST_TIMEOUT_SECONDS = 3;
    private const RETRY_ATTEMPTS = 3;

    public static function create(DynamoDbSettings $settings, ?callable $handler = null): DynamoDbClient
    {
        $config = [
            'version' => self::API_VERSION,
            'http' => ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS, 'timeout' => self::REQUEST_TIMEOUT_SECONDS],
            'retries' => ['mode' => 'standard', 'max_attempts' => self::RETRY_ATTEMPTS],
        ];
        if ($settings->region !== null) {
            $config['region'] = $settings->region;
        }
        if ($handler !== null) {
            $config['handler'] = $handler; // a test seam: lets tests see the requests without a network
        }
        if ($settings->endpoint !== null) {
            $config['endpoint'] = $settings->endpoint;
        }

        return new DynamoDbClient($config);
    }
}
