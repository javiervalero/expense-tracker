<?php

declare(strict_types=1);

namespace App\Receipts\Http;

use App\Http\Response;
use App\Infrastructure\S3\ViewUrlSigner;
use App\Infrastructure\Time\StoredTime;
use App\Receipts\Receipt;
use App\Receipts\ReceiptStatus;
use JsonException;

/**
 * The body of `GET /expenses/{id}/receipt` (C6), mapped field by field to exactly `{receiptId, status, contentType, size,
 * createdAt, failureReason, viewUrl, thumbnailUrl, urlsExpireAt}`, so no storage key leaves the server.
 *
 * The addresses are signed ONLY for a `ready` receipt, and with the STORED `contentType`: once ready, that is the type
 * the worker verified from the file's bytes, so a hostile file cannot be served as something else. Nothing from the
 * request reaches the signing. A PDF has a view address only. The addresses are credentials: this class never logs.
 */
final class ReceiptResponse
{
    private function __construct() {}

    /**
     * @throws JsonException
     */
    public static function ok(Receipt $receipt, ViewUrlSigner $urls): Response
    {
        $viewUrl = null;
        $thumbnailUrl = null;
        $expiresAt = null;
        if ($receipt->status === ReceiptStatus::Ready) {
            $original = $urls->original($receipt->objectKey, $receipt->contentType);
            $viewUrl = $original->url;
            $expiresAt = StoredTime::format($original->expiresAt);
            if ($receipt->contentType->isImage()) {
                $thumbnailUrl = $urls->thumbnail($receipt->objectKey)->url;
            }
        }

        return Response::json(200, [
            'receiptId' => $receipt->receiptId(),
            'status' => $receipt->status->value,
            'contentType' => $receipt->contentType->value,
            'size' => $receipt->size,
            'createdAt' => StoredTime::format($receipt->createdAt),
            'failureReason' => $receipt->failureReason?->value,
            'viewUrl' => $viewUrl,
            'thumbnailUrl' => $thumbnailUrl,
            'urlsExpireAt' => $expiresAt,
        ]);
    }
}
