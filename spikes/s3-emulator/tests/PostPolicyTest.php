<?php

declare(strict_types=1);

namespace Spike;

/** ADR 0007 criterion 1: presigned POST policy conditions are enforced (security control). */
final class PostPolicyTest extends S3TestCase
{
    /** @param array{status: int, code: ?string, message: ?string, body: string} $result */
    private function assertRejected(string $name, string $key, array $result): void
    {
        $this->record($name, ['status' => $result['status'], 'code' => $result['code'], 'message' => $result['message']]);
        $this->assertGreaterThanOrEqual(400, $result['status'], "{$name}: expected a 4xx, got {$result['status']}");
        $this->assertLessThan(500, $result['status'], "{$name}: expected a 4xx, not a server error");
        $this->assertNotNull($result['code'], "{$name}: expected an S3 XML error code");
        $this->assertFalse($this->objectExists(BUCKET_RECEIPTS, $key), "{$name}: object must not be stored");
    }

    public function testValidUploadIsAccepted(): void
    {
        $key = $this->newKey();

        $result = $this->uploadValid($key);

        $this->record(__FUNCTION__, ['status' => $result['status'], 'code' => $result['code']]);
        $this->assertContains($result['status'], [200, 201, 204]);
        $head = $this->s3->headObject(['Bucket' => BUCKET_RECEIPTS, 'Key' => $key]);
        $this->assertSame(100, (int) $head['ContentLength']);
        $this->assertSame(self::CONTENT_TYPE, $head['ContentType']);
    }

    public function testBodyOverMaxSizeIsRejected(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), str_repeat('x', self::MAX_SIZE + 1));

        $this->assertRejected(__FUNCTION__, $key, $result);
    }

    public function testBodyUnderMinSizeIsRejected(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), str_repeat('x', self::MIN_SIZE - 1));

        $this->assertRejected(__FUNCTION__, $key, $result);
    }

    public function testWrongContentTypeIsRejected(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), $this->validBody(), ['Content-Type' => 'text/html']);

        $this->assertRejected(__FUNCTION__, $key, $result);
    }

    public function testDifferentKeyIsRejected(): void
    {
        $signedKey = $this->newKey();
        $otherKey = $this->newKey();

        $result = $this->sendPost($this->presignedPost($signedKey), $this->validBody(), ['key' => $otherKey]);

        $this->assertRejected(__FUNCTION__, $otherKey, $result);
        $this->assertFalse($this->objectExists(BUCKET_RECEIPTS, $signedKey));
    }

    public function testExtraUnsignedFieldIsRejected(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), $this->validBody(), [], ['x-amz-meta-injected' => 'evil']);

        $this->assertRejected(__FUNCTION__, $key, $result);
    }

    public function testExpiredPolicyIsRejected(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key, '-5 minutes'), $this->validBody());

        $this->assertRejected(__FUNCTION__, $key, $result);
    }

    public function testBadSignatureIsRejected(): void
    {
        $key = $this->newKey();
        $form = $this->presignedPost($key);
        $signature = $form->getFormInputs()['X-Amz-Signature'];
        $tampered = ($signature[0] === '0' ? '1' : '0') . substr($signature, 1);

        $result = $this->sendPost($form, $this->validBody(), ['X-Amz-Signature' => $tampered]);

        $this->assertRejected(__FUNCTION__, $key, $result);
    }
}
