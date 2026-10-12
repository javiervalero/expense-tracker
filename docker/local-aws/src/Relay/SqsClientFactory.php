<?php

declare(strict_types=1);

namespace LocalAws\Relay;

use Aws\Sqs\SqsClient;

/**
 * Builds the SQS client of the relay. The HTTP timeouts are explicit so that one hung connection cannot hold the
 * relay for long: a slow queue ends in a 502, and the S3 emulator retries from its own queue. So the SDK itself does
 * not retry (the emulator's retry is the only one; two layers would multiply the wait).
 */
final class SqsClientFactory
{
    private const CONNECT_TIMEOUT_SECONDS = 1;
    private const REQUEST_TIMEOUT_SECONDS = 3;
    private const MAX_RETRIES = 0;

    /**
     * @return array<string, mixed>
     */
    public static function options(RelaySettings $settings): array
    {
        return [
            'version' => '2012-11-05',
            'region' => $settings->region,
            'endpoint' => $settings->sqsEndpoint,
            'credentials' => ['key' => $settings->accessKeyId, 'secret' => $settings->secretAccessKey],
            'http' => ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS, 'timeout' => self::REQUEST_TIMEOUT_SECONDS],
            'retries' => self::MAX_RETRIES,
        ];
    }

    /**
     * @param (callable(\Psr\Http\Message\RequestInterface, array<string, mixed>): \GuzzleHttp\Promise\PromiseInterface)|null $httpHandler
     *        replaces the network layer (tests only); null uses the SDK's default
     */
    public static function create(RelaySettings $settings, ?callable $httpHandler = null): SqsClient
    {
        return new SqsClient(self::options($settings) + ($httpHandler === null ? [] : ['http_handler' => $httpHandler]));
    }
}
