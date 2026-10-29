<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use Aws\S3\S3Client;

/**
 * The one place an S3 client is built (an architecture test guards it). No credentials are passed, ever: the SDK's
 * default chain finds them (the execution role on Lambda; the environment locally).
 *
 * Two clients, because two addresses are involved locally (decision 15):
 * - {@see self::forSigning()} makes upload policies and view URLs. It makes no network call; its endpoint is the PUBLIC
 *   one (`S3_PUBLIC_ENDPOINT`), because a signature covers the host the browser will use;
 * - {@see self::forCalls()} is what the API sends requests with: the internal endpoint (`S3_ENDPOINT`).
 *
 * Addressing style: with a local endpoint, path style (the single-host emulator needs it). Without one (AWS), path
 * style is OFF, so URLs are virtual-hosted (`<bucket>.s3.<region>.amazonaws.com`) and every bucket is its own browser
 * origin; path style would put all buckets on one shared origin.
 *
 * Like the DynamoDB factory: short timeouts and bounded retries; outside Lambda (where the runtime sets `AWS_REGION`) the
 * region must come from `AWS_REGION` in the settings or from the SDK's own chain (shared config file,
 * `AWS_DEFAULT_REGION`). With none, building the client throws (the SDK requires a region for S3), and the caller
 * reports it as a failure of the S3 setup.
 */
final class S3ClientFactory
{
    private const API_VERSION = '2006-03-01';
    private const CONNECT_TIMEOUT_SECONDS = 2;
    private const REQUEST_TIMEOUT_SECONDS = 20;
    private const RETRY_ATTEMPTS = 3;

    public static function forSigning(S3Settings $settings, ?callable $handler = null): S3Client
    {
        return self::create($settings, $settings->publicEndpoint, $handler);
    }

    public static function forCalls(S3Settings $settings, ?callable $handler = null): S3Client
    {
        return self::create($settings, $settings->endpoint, $handler);
    }

    private static function create(S3Settings $settings, ?string $endpoint, ?callable $handler): S3Client
    {
        $config = [
            'version' => self::API_VERSION,
            // The environment (AWS_ENDPOINT_URL, AWS_ENDPOINT_URL_S3) or a shared config file must never move the client:
            // the endpoint is the application's setting or the AWS default, nothing else.
            'ignore_configured_endpoint_urls' => true,
            'use_path_style_endpoint' => $endpoint !== null,
            'http' => ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS, 'timeout' => self::REQUEST_TIMEOUT_SECONDS],
            'retries' => ['mode' => 'standard', 'max_attempts' => self::RETRY_ATTEMPTS],
        ];
        if ($settings->region !== null) {
            $config['region'] = $settings->region;
        }
        if ($endpoint !== null) {
            $config['endpoint'] = $endpoint;
        }
        if ($handler !== null) {
            $config['handler'] = $handler; // a test seam: lets tests see the requests without a network
        }

        return new S3Client($config);
    }
}
