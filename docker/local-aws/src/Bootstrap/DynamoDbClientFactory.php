<?php

declare(strict_types=1);

namespace LocalAws\Bootstrap;

use Aws\DynamoDb\DynamoDbClient;

/**
 * Builds the DynamoDB client of the bootstrap. The HTTP timeouts are explicit so that one hung connection can never
 * outlast the ServiceWaiter deadline: each attempt is bounded, and the SDK retries at most once.
 */
final class DynamoDbClientFactory
{
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 10;
    private const MAX_RETRIES = 1;

    /**
     * @return array<string, mixed>
     */
    public static function options(BootstrapSettings $settings): array
    {
        return [
            'version' => '2012-08-10',
            'region' => $settings->region,
            'endpoint' => $settings->dynamoDbEndpoint,
            'credentials' => ['key' => $settings->accessKeyId, 'secret' => $settings->secretAccessKey],
            'http' => ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS, 'timeout' => self::REQUEST_TIMEOUT_SECONDS],
            'retries' => self::MAX_RETRIES,
        ];
    }

    /**
     * @param (callable(\Psr\Http\Message\RequestInterface, array<string, mixed>): \GuzzleHttp\Promise\PromiseInterface)|null $httpHandler
     *        replaces the network layer (tests only); null uses the SDK's default
     */
    public static function create(BootstrapSettings $settings, ?callable $httpHandler = null): DynamoDbClient
    {
        return new DynamoDbClient(self::options($settings) + ($httpHandler === null ? [] : ['http_handler' => $httpHandler]));
    }
}
