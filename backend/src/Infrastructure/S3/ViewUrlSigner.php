<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use App\Infrastructure\Clock\Clock;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectKey;
use Aws\S3\S3Client;

/**
 * Presigned GET URLs (C6) for one original or one thumbnail, valid {@see ReceiptLimits::VIEW_URL_TTL_SECONDS}. The
 * response headers are forced in the signature: `Content-Type` is the type the worker verified (never what S3 stored),
 * and `Content-Disposition: inline; filename="receipt.<ext>"` names the file without any client text; `Cache-Control:
 * private, no-store` keeps a shared cache or the browser from keeping the file. The URL is served
 * from the bucket's own origin, not the app's (C14).
 *
 * Takes the SIGNING client ({@see S3ClientFactory::forSigning()}): no network call is made.
 */
final readonly class ViewUrlSigner
{
    public function __construct(
        private S3Client $signingClient,
        private string $receiptsBucket,
        private string $thumbnailsBucket,
        private Clock $clock,
    ) {}

    public function original(ReceiptObjectKey $key, ReceiptContentType $type): SignedUrl
    {
        return $this->sign($this->receiptsBucket, $key->originalKey(), $type);
    }

    /** Thumbnails are always JPEG. */
    public function thumbnail(ReceiptObjectKey $key): SignedUrl
    {
        return $this->sign($this->thumbnailsBucket, $key->thumbnailKey(), ReceiptContentType::Jpeg);
    }

    private function sign(string $bucket, string $objectKey, ReceiptContentType $type): SignedUrl
    {
        $command = $this->signingClient->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $objectKey,
            'ResponseContentType' => $type->value,
            'ResponseContentDisposition' => 'inline; filename="receipt.' . $type->extension() . '"',
            'ResponseCacheControl' => 'private, no-store',
        ]);
        // A relative lifetime: the SDK measures it from the real signing time, which is what the signature holds.
        // `expiresAt` below comes from the Clock; the two are the same instant in production (the system clock).
        $request = $this->signingClient->createPresignedRequest($command, '+' . ReceiptLimits::VIEW_URL_TTL_SECONDS . ' seconds');

        return new SignedUrl(
            (string) $request->getUri(),
            $this->clock->now()->modify('+' . ReceiptLimits::VIEW_URL_TTL_SECONDS . ' seconds'),
        );
    }
}
