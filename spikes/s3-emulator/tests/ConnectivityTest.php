<?php

declare(strict_types=1);

namespace Spike;

use GuzzleHttp\Psr7\Uri;

/** Health endpoints and presigned GET signed for the host-facing endpoint but used from inside the network. */
final class ConnectivityTest extends S3TestCase
{
    public function testHealthEndpointsAnswer200(): void
    {
        $live = $this->http->get($this->endpoint() . '/minio/health/live');
        $ready = $this->http->get($this->endpoint() . '/minio/health/ready');

        $this->record(__FUNCTION__, ['live' => $live->getStatusCode(), 'ready' => $ready->getStatusCode()]);
        $this->assertSame(200, $live->getStatusCode());
        $this->assertSame(200, $ready->getStatusCode());
    }

    public function testPresignedGetSignedForHostEndpointWorksFromInsideNetworkWithHostHeader(): void
    {
        $publicEndpoint = \env_or('S3_PUBLIC_ENDPOINT', 'http://localhost:19100');
        $key = $this->newKey();
        $this->s3->putObject(['Bucket' => BUCKET_RECEIPTS, 'Key' => $key, 'Body' => 'hello-presigned']);
        $request = \s3_client($publicEndpoint)->createPresignedRequest(
            \s3_client($publicEndpoint)->getCommand('GetObject', ['Bucket' => BUCKET_RECEIPTS, 'Key' => $key]),
            '+5 minutes',
        );
        $signed = $request->getUri();
        $this->assertSame('localhost:19100', $signed->getHost() . ':' . $signed->getPort());

        // what the API container will do in Phase 5: connect to s3:9000 but keep the signed Host
        $internal = (new Uri((string) $signed))->withScheme('http')->withHost('s3')->withPort(9000);
        $withHost = $this->http->get((string) $internal, ['headers' => ['Host' => 'localhost:19100']]);
        // control: same URL, honest Host header, must not validate
        $withoutHost = $this->http->get((string) $internal);

        $this->record(__FUNCTION__, [
            'withSignedHostHeader' => $withHost->getStatusCode(),
            'withoutHostOverride' => $withoutHost->getStatusCode(),
            'withoutHostOverrideCode' => $this->summarise($withoutHost->getStatusCode(), (string) $withoutHost->getBody())['code'],
        ]);
        $this->assertSame(200, $withHost->getStatusCode());
        $this->assertSame('hello-presigned', (string) $withHost->getBody());
        $this->assertSame(403, $withoutHost->getStatusCode(), 'host is part of the signature');
    }
}
