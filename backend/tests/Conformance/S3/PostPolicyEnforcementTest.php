<?php

declare(strict_types=1);

namespace App\Tests\Conformance\S3;

/**
 * ADR 0007 criterion 1, a security control: the emulator enforces the conditions of a presigned POST policy the way
 * S3 does (size range, content type, exact key, no unsigned fields, expiry, signature). Each rejection is checked
 * for the HTTP status, the S3 error code and, above all, that nothing was stored. It also covers the access control
 * around the policy: anonymous reads and listings of the receipts bucket are refused.
 *
 * Keys are under `conformance/` (outside the bucket's notification rule), except in the one test that uses the real
 * `receipts/<user>/<expense>/<receipt>` shape.
 */
final class PostPolicyEnforcementTest extends S3ConformanceCase
{
    public function testValidUploadIsAcceptedAndStoredWithItsContentType(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), self::validBody());

        self::assertSame(204, $result['status']);
        $head = $this->s3->headObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key]);
        self::assertSame(100, $head->get('ContentLength'));
        self::assertSame(self::CONTENT_TYPE, $head->get('ContentType'));
    }

    /**
     * The only test that writes under the real key shape. Creating that object emits exactly ONE ObjectCreated
     * event (ADR 0007: one webhook per upload under `receipts/`) into the emulator's webhook queue; the relay (WP9)
     * will pick it up and the worker will ignore it, since the object is not an image. The object is deleted in
     * tearDown (a delete emits no event).
     */
    public function testUploadUnderTheRealReceiptKeyShapeIsAccepted(): void
    {
        $key = $this->newReceiptShapedKey();

        $result = $this->sendPost($this->presignedPost($key), self::validBody());

        self::assertSame(204, $result['status']);
        self::assertTrue($this->objectExists($key));
    }

    public function testBodyExactlyAtTheMinimumSizeIsAccepted(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), str_repeat('x', self::MIN_SIZE));

        self::assertSame(204, $result['status']);
        self::assertSame(self::MIN_SIZE, $this->s3->headObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key])->get('ContentLength'));
    }

    public function testBodyExactlyAtTheMaximumSizeIsAccepted(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), str_repeat('x', self::MAX_SIZE));

        self::assertSame(204, $result['status']);
        self::assertSame(self::MAX_SIZE, $this->s3->headObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key])->get('ContentLength'));
    }

    public function testBodyOverTheMaximumSizeIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), str_repeat('x', self::MAX_SIZE + 1));

        self::assertRejected(400, 'EntityTooLarge', $result);
        self::assertFalse($this->objectExists($key));
    }

    public function testBodyUnderTheMinimumSizeIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), str_repeat('x', self::MIN_SIZE - 1));

        self::assertRejected(400, 'EntityTooSmall', $result);
        self::assertFalse($this->objectExists($key));
    }

    public function testWrongContentTypeIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), self::validBody(), ['Content-Type' => 'text/html']);

        self::assertRejected(403, 'AccessDenied', $result);
        self::assertFalse($this->objectExists($key));
    }

    public function testADifferentKeyThanTheSignedOneIsRejectedAndNothingIsStored(): void
    {
        $signedKey = $this->newKey();
        $otherKey = $this->newKey();

        $result = $this->sendPost($this->presignedPost($signedKey), self::validBody(), ['key' => $otherKey]);

        self::assertRejected(403, 'AccessDenied', $result);
        self::assertFalse($this->objectExists($otherKey), 'the key the caller asked for must not exist');
        self::assertFalse($this->objectExists($signedKey), 'the signed key must not exist either');
    }

    public function testAnExtraUnsignedFieldIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), self::validBody(), [], ['x-amz-meta-injected' => 'evil']);

        self::assertRejected(403, 'AccessDenied', $result);
        self::assertFalse($this->objectExists($key));
    }

    public function testAnExpiredPolicyIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key, '-5 minutes'), self::validBody());

        self::assertRejected(403, 'AccessDenied', $result);
        self::assertStringContainsStringIgnoringCase('expire', (string) $result['message'], 'the message should say the policy expired');
        self::assertFalse($this->objectExists($key));
    }

    public function testAPolicyWithAWidenedSizeRangeAndTheOriginalSignatureIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();
        $form = $this->presignedPost($key);
        $widened = self::withSizeRange(self::formInput($form, 'Policy'), 0, 10_000_000);

        $result = $this->sendPost($form, str_repeat('x', self::MAX_SIZE + 1), ['Policy' => $widened]);

        self::assertRejected(403, 'SignatureDoesNotMatch', $result);
        self::assertFalse($this->objectExists($key));
    }

    public function testAPolicySignedForTheReceiptsBucketIsRejectedOnTheThumbnailsBucket(): void
    {
        $key = $this->newKey(self::THUMBNAILS_BUCKET);
        $form = $this->presignedPost($key);
        $thumbnailsAction = $this->localAws->s3Endpoint() . '/' . self::THUMBNAILS_BUCKET;
        self::assertSame($this->localAws->s3Endpoint() . '/' . self::RECEIPTS_BUCKET, self::formAction($form));

        $result = $this->sendPost($form, self::validBody(), actionUrl: $thumbnailsAction);

        self::assertRejected(403, 'AccessDenied', $result);
        self::assertFalse($this->objectExists($key, self::THUMBNAILS_BUCKET));
        self::assertFalse($this->objectExists($key), 'nothing may land in the receipts bucket either');
    }

    public function testAFormSignedWithTheWrongSecretIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();
        $form = $this->presignedPost($key, signer: $this->s3ClientWithSecret('not-the-real-secret'));

        $result = $this->sendPost($form, self::validBody());

        self::assertRejected(403, 'SignatureDoesNotMatch', $result);
        self::assertFalse($this->objectExists($key));
    }

    public function testAFormWithoutPolicyAndSignatureIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost(
            $this->presignedPost($key),
            self::validBody(),
            omitFields: ['Policy', 'X-Amz-Signature', 'X-Amz-Credential', 'X-Amz-Algorithm', 'X-Amz-Date'],
        );

        self::assertRejected(400, 'AuthorizationQueryParametersError', $result);
        self::assertFalse($this->objectExists($key));
    }

    public function testAFormWithAPolicyButNoSignatureIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), self::validBody(), omitFields: ['X-Amz-Signature']);

        // Silo answers 403 SignatureDoesNotMatch here (not 400): still a rejection, and nothing is stored.
        self::assertRejected(403, 'SignatureDoesNotMatch', $result);
    }

    public function testAFormWithoutTheSignedContentTypeFieldIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), self::validBody(), omitFields: ['Content-Type']);

        self::assertRejected(403, 'AccessDenied', $result);
        self::assertFalse($this->objectExists($key));
    }

    public function testAFormWithoutTheKeyFieldIsRejectedAndNothingIsStored(): void
    {
        $key = $this->newKey();

        $result = $this->sendPost($this->presignedPost($key), self::validBody(), omitFields: ['key']);

        self::assertRejected(400, 'MalformedPOSTRequest', $result);
        self::assertFalse($this->objectExists($key));
        self::assertFalse($this->objectExists('${filename}'), 'a missing key must not fall back to the file name');
        self::assertFalse($this->objectExists('receipt.jpg'), 'a missing key must not fall back to the file name');
    }

    public function testAnonymousGetOfAnObjectIsRefused(): void
    {
        $key = $this->newKey();
        $this->s3->putObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key, 'Body' => 'private receipt']);

        $response = $this->http->get($this->localAws->s3Endpoint() . '/' . self::RECEIPTS_BUCKET . '/' . $key);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('AccessDenied', self::summarise(403, (string) $response->getBody())['code']);
        self::assertStringNotContainsString('private receipt', (string) $response->getBody());
    }

    public function testAnonymousListingOfTheBucketIsRefused(): void
    {
        $key = $this->newKey();
        $this->s3->putObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key, 'Body' => 'private receipt']);

        $response = $this->http->get($this->localAws->s3Endpoint() . '/' . self::RECEIPTS_BUCKET . '?list-type=2');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('AccessDenied', self::summarise(403, (string) $response->getBody())['code']);
        self::assertStringNotContainsString($key, (string) $response->getBody(), 'the listing must not leak key names');
    }

    public function testATamperedSignatureIsRejectedAndNotStored(): void
    {
        $key = $this->newKey();
        $form = $this->presignedPost($key);
        $signature = self::formInput($form, 'X-Amz-Signature');
        $tampered = ($signature[0] === '0' ? '1' : '0') . substr($signature, 1);

        $result = $this->sendPost($form, self::validBody(), ['X-Amz-Signature' => $tampered]);

        self::assertRejected(403, 'SignatureDoesNotMatch', $result);
        self::assertFalse($this->objectExists($key));
    }

    /**
     * The same policy document with another content-length-range, as a signature-less tamperer would write it.
     */
    private static function withSizeRange(string $encodedPolicy, int $min, int $max): string
    {
        $decoded = base64_decode($encodedPolicy, true);
        self::assertIsString($decoded);
        $policy = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($policy);
        $conditions = $policy['conditions'];
        self::assertIsArray($conditions);
        $policy['conditions'] = array_map(
            static fn(mixed $condition): mixed => is_array($condition) && ($condition[0] ?? null) === 'content-length-range'
                ? ['content-length-range', $min, $max]
                : $condition,
            $conditions,
        );

        return base64_encode(json_encode($policy, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{status: int, code: ?string, message: ?string} $result
     */
    private static function assertRejected(int $status, string $code, array $result): void
    {
        self::assertSame($status, $result['status'], 'HTTP status of the rejection');
        self::assertSame($code, $result['code'], 'S3 error code of the rejection');
    }
}
