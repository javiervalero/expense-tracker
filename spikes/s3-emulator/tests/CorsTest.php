<?php

declare(strict_types=1);

namespace Spike;

/** Browser uploads from the Vite dev server: preflight allowed for the dev origin only, after PutBucketCors. */
final class CorsTest extends S3TestCase
{
    private const ALLOWED_ORIGIN = 'http://localhost:5173';
    private const OTHER_ORIGIN = 'http://evil.example';

    private function preflight(string $origin): array
    {
        $response = $this->http->request('OPTIONS', $this->endpoint() . '/' . BUCKET_RECEIPTS, [
            'headers' => [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type',
            ],
        ]);
        $headers = array_change_key_case(array_map(static fn (array $v): string => implode(',', $v), $response->getHeaders()), CASE_LOWER);

        return ['status' => $response->getStatusCode(), 'headers' => $headers];
    }

    public function testPreflightAllowedForDevOriginAndRefusedForOthers(): void
    {
        $this->s3->putBucketCors([
            'Bucket' => BUCKET_RECEIPTS,
            'CORSConfiguration' => ['CORSRules' => [[
                'AllowedOrigins' => [self::ALLOWED_ORIGIN],
                'AllowedMethods' => ['POST', 'GET'],
                'AllowedHeaders' => ['*'],
                'ExposeHeaders' => ['ETag'],
                'MaxAgeSeconds' => 600,
            ]]],
        ]);

        $allowed = $this->preflight(self::ALLOWED_ORIGIN);
        $refused = $this->preflight(self::OTHER_ORIGIN);

        $this->record(__FUNCTION__, [
            'allowedStatus' => $allowed['status'],
            'allowedAcao' => $allowed['headers']['access-control-allow-origin'] ?? null,
            'otherStatus' => $refused['status'],
            'otherAcao' => $refused['headers']['access-control-allow-origin'] ?? null,
        ]);
        $this->assertSame(self::ALLOWED_ORIGIN, $allowed['headers']['access-control-allow-origin'] ?? null);
        $this->assertContains($allowed['status'], [200, 204]);
        $this->assertArrayNotHasKey('access-control-allow-origin', $refused['headers'], 'other origins must not be allowed');
        $this->assertGreaterThanOrEqual(400, $refused['status']);
    }
}
