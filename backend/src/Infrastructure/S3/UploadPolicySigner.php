<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use App\Infrastructure\Clock\Clock;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectKey;
use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;

/**
 * Makes the presigned POST that lets the browser put ONE receipt into the receipts bucket (C5). The SDK's
 * PostObjectV4 copies the conditions as given and adds none for bucket or key, so every rule is listed here:
 *
 * - `bucket` equals the receipts bucket;
 * - `key` equals the one object key (built from three validated ids, never from client text);
 * - `Content-Type` equals the declared type;
 * - `content-length-range` is the declared size to the declared size (owner decision: the file must be exactly as big
 *   as the client said, so the declared size the record holds is the real one); the size itself must be 1 to
 *   {@see ReceiptLimits::MAX_BYTES};
 * - the policy expires {@see ReceiptLimits::POLICY_TTL_SECONDS} after the injected clock's now.
 *
 * No `acl` and no `success_action_redirect`; S3 refuses form fields the policy does not cover. The SDK adds the three
 * signature conditions (`X-Amz-Date`, `X-Amz-Credential`, `X-Amz-Algorithm`) and, with session credentials, the
 * security token; that last part is internal to the SDK.
 *
 * Takes the SIGNING client ({@see S3ClientFactory::forSigning()}): no network call is made.
 */
final readonly class UploadPolicySigner
{
    public function __construct(
        private S3Client $signingClient,
        private string $receiptsBucket,
        private Clock $clock,
    ) {}

    /**
     * @throws \InvalidArgumentException when the size is not from 1 to {@see ReceiptLimits::MAX_BYTES}
     */
    public function sign(ReceiptObjectKey $key, ReceiptContentType $type, int $size): SignedUpload
    {
        if ($size < 1 || $size > ReceiptLimits::MAX_BYTES) {
            throw new \InvalidArgumentException('An upload size must be between 1 and ' . ReceiptLimits::MAX_BYTES . ' bytes.');
        }
        $objectKey = $key->originalKey();
        // The policy expiry comes from the Clock; the SDK dates the signature (X-Amz-Date) from the real time. Same
        // instant in production (the system clock); a frozen clock in a test moves only the expiry.
        $expiresAt = $this->clock->now()->modify('+' . ReceiptLimits::POLICY_TTL_SECONDS . ' seconds');
        $form = new PostObjectV4(
            $this->signingClient,
            $this->receiptsBucket,
            ['key' => $objectKey, 'Content-Type' => $type->value],
            [
                ['bucket' => $this->receiptsBucket],
                ['eq', '$key', $objectKey],
                ['eq', '$Content-Type', $type->value],
                ['content-length-range', $size, $size],
            ],
            $expiresAt,
        );

        $fields = [];
        foreach ($form->getFormInputs() as $name => $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException('The SDK returned a form field that is not text.');
            }
            $fields[(string) $name] = $value;
        }
        $action = $form->getFormAttributes()['action'] ?? null;
        if (!is_string($action)) {
            throw new \UnexpectedValueException('The SDK returned no form action.');
        }

        return new SignedUpload($action, $fields, $expiresAt);
    }
}
