<?php

declare(strict_types=1);

namespace App\Tests\Conformance\S3;

use GuzzleHttp\Psr7\Uri;

/**
 * Phase 5 relies on this: a presigned GET is signed for the host-facing endpoint (what the browser uses,
 * `http://localhost:9000`), while code inside the Compose network reaches the emulator at `http://s3:9000`.
 * The signature covers the Host header, as on AWS: the URL works with the signed Host and fails with the real one.
 */
final class PresignedGetTest extends S3ConformanceCase
{
    private const OBJECT_BODY = 'hello-presigned';

    private function signedUrlForThePublicEndpoint(string $key, string $expiration = '+5 minutes'): Uri
    {
        $this->s3->putObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key, 'Body' => self::OBJECT_BODY]);
        $public = $this->localAws->s3ForPublicEndpoint();
        $request = $public->createPresignedRequest(
            $public->getCommand('GetObject', ['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key]),
            $expiration,
        );

        return new Uri((string) $request->getUri());
    }

    private function signedHost(Uri $signed): string
    {
        return $signed->getHost() . ':' . $signed->getPort();
    }

    private function viaTheInternalEndpoint(Uri $signed): Uri
    {
        $internal = new Uri($this->localAws->s3Endpoint());

        return $signed->withScheme($internal->getScheme())->withHost($internal->getHost())->withPort($internal->getPort());
    }

    public function testUrlSignedForThePublicEndpointCarriesThePublicHost(): void
    {
        $signed = $this->signedUrlForThePublicEndpoint($this->newKey());

        self::assertSame('localhost:9000', $this->signedHost($signed));
    }

    public function testUrlWorksOnTheInternalEndpointWhenSentWithTheSignedHostHeader(): void
    {
        $signed = $this->signedUrlForThePublicEndpoint($this->newKey());

        $response = $this->http->get((string) $this->viaTheInternalEndpoint($signed), ['headers' => ['Host' => $this->signedHost($signed)]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::OBJECT_BODY, (string) $response->getBody());
    }

    public function testUrlFailsOnTheInternalEndpointWithTheWrongHostHeader(): void
    {
        $signed = $this->signedUrlForThePublicEndpoint($this->newKey());

        $response = $this->http->get((string) $this->viaTheInternalEndpoint($signed));

        self::assertSame(403, $response->getStatusCode(), 'the Host header is part of the signature');
        self::assertSame('SignatureDoesNotMatch', self::summarise(403, (string) $response->getBody())['code']);
    }

    public function testAnExpiredUrlIsRefused(): void
    {
        $signed = $this->signedUrlForThePublicEndpoint($this->newKey(), '+1 second');
        // Expiry is measured in whole seconds from the signing time: wait until it is surely over.
        sleep(3);

        $response = $this->http->get((string) $this->viaTheInternalEndpoint($signed), ['headers' => ['Host' => $this->signedHost($signed)]]);

        self::assertSame(403, $response->getStatusCode());
        $error = self::summarise(403, (string) $response->getBody());
        self::assertSame('AccessDenied', $error['code']);
        self::assertStringContainsStringIgnoringCase('expire', (string) $error['message']);
        self::assertStringNotContainsString(self::OBJECT_BODY, (string) $response->getBody());
    }

    public function testATamperedSignatureIsRefused(): void
    {
        $signed = $this->signedUrlForThePublicEndpoint($this->newKey());
        parse_str($signed->getQuery(), $query);
        $signature = $query['X-Amz-Signature'] ?? null;
        self::assertIsString($signature);
        $query['X-Amz-Signature'] = ($signature[0] === '0' ? '1' : '0') . substr($signature, 1);
        $tampered = $this->viaTheInternalEndpoint($signed)->withQuery(http_build_query($query));

        $response = $this->http->get((string) $tampered, ['headers' => ['Host' => $this->signedHost($signed)]]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('SignatureDoesNotMatch', self::summarise(403, (string) $response->getBody())['code']);
        self::assertStringNotContainsString(self::OBJECT_BODY, (string) $response->getBody());
    }
}
