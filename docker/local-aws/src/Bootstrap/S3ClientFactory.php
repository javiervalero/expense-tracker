<?php

declare(strict_types=1);

namespace LocalAws\Bootstrap;

use Aws\S3\S3Client;

/**
 * Builds the S3 client of the bootstrap. The HTTP timeouts are explicit so that one hung connection can never
 * outlast the ServiceWaiter deadline: each attempt is bounded, and the SDK retries at most once.
 */
final class S3ClientFactory
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
            'version' => '2006-03-01',
            'region' => $settings->region,
            'endpoint' => $settings->s3Endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $settings->accessKeyId, 'secret' => $settings->secretAccessKey],
            'http' => ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS, 'timeout' => self::REQUEST_TIMEOUT_SECONDS],
            'retries' => self::MAX_RETRIES,
        ];
    }

    /**
     * @param (callable(\Psr\Http\Message\RequestInterface, array<string, mixed>): \GuzzleHttp\Promise\PromiseInterface)|null $httpHandler
     *        replaces the network layer (tests only); null uses the SDK's default
     */
    public static function create(BootstrapSettings $settings, ?callable $httpHandler = null): S3Client
    {
        return new S3Client(self::options($settings) + ($httpHandler === null ? [] : ['http_handler' => $httpHandler]));
    }
}
