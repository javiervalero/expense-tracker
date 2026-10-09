<?php

declare(strict_types=1);

namespace LocalAws\Runner;

use Aws\Sqs\SqsClient;

/**
 * Builds the SQS client of the runner. Timeouts are explicit: the request timeout must outlast the long poll
 * ({@see SqsQueueClient::WAIT_TIME_SECONDS}) or every empty poll would end in a timeout. The SDK does not retry: the
 * runner's own loop retries (with a pause), so a down queue is not hammered by two layers of retries.
 */
final class QueueClientFactory
{
    private const CONNECT_TIMEOUT_SECONDS = 1;
    private const REQUEST_TIMEOUT_SECONDS = SqsQueueClient::WAIT_TIME_SECONDS + 10;
    private const MAX_RETRIES = 0;

    /**
     * @return array{version: string, region: string, endpoint: string, credentials: array{key: string, secret: string}, http: array{connect_timeout: int, timeout: int}, retries: int}
     */
    public static function options(RunnerSettings $settings): array
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
    public static function create(RunnerSettings $settings, ?callable $httpHandler = null): SqsClient
    {
        return new SqsClient(self::options($settings) + ($httpHandler === null ? [] : ['http_handler' => $httpHandler]));
    }
}
