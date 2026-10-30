<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Receipts\ReceiptObjectKey;
use Aws\Result;

/**
 * The stored shapes of a receipt record (C2) as DynamoDB answers them, for the unit tests of the receipt handlers.
 * Times are around {@see FrozenClock}'s default `2026-10-16T12:00:00Z`. Ids are invented.
 */
final class StoredReceipt
{
    public const string USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    public const string OTHER_USER = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    public const string EXPENSE = '5c8e1f3a-2b4d-4a6c-8e0f-1a2b3c4d5e6f';
    public const string RECEIPT = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';
    public const string CREATED = '2026-10-16T11:00:00Z';

    private function __construct() {}

    public static function key(): ReceiptObjectKey
    {
        return ReceiptObjectKey::from(self::USER, self::EXPENSE, self::RECEIPT);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function item(
        string $status = 'pending',
        string $contentType = 'image/png',
        ?string $expiresAt = '2026-10-17T11:00:00+00:00',
        ?string $failureReason = null,
        ?string $processedAt = null,
    ): array {
        $item = [
            'PK' => ['S' => 'USER#' . self::USER],
            'SK' => ['S' => 'RCPT#' . self::EXPENSE],
            'receiptId' => ['S' => self::RECEIPT],
            'objectKey' => ['S' => self::key()->originalKey()],
            'status' => ['S' => $status],
            'contentType' => ['S' => $contentType],
            'size' => ['N' => '1234'],
            'createdAt' => ['S' => self::CREATED],
        ];
        if ($expiresAt !== null) {
            $item['expiresAt'] = ['N' => (string) (new \DateTimeImmutable($expiresAt))->getTimestamp()];
        }
        if ($failureReason !== null) {
            $item['failureReason'] = ['S' => $failureReason];
        }
        if ($processedAt !== null) {
            $item['processedAt'] = ['S' => $processedAt];
        }

        return $item;
    }

    /** @return Result<string, mixed> a GetItem answer holding the record */
    public static function found(string $status = 'pending', string $contentType = 'image/png', ?string $expiresAt = '2026-10-17T11:00:00+00:00', ?string $failureReason = null, ?string $processedAt = null): Result
    {
        return new Result(['Item' => self::item($status, $contentType, $expiresAt, $failureReason, $processedAt)]);
    }

    /** @return Result<string, mixed> a DeleteItem ALL_OLD answer holding the record */
    public static function deleted(string $status = 'pending', string $contentType = 'image/png', ?string $expiresAt = '2026-10-17T11:00:00+00:00', ?string $failureReason = null, ?string $processedAt = null): Result
    {
        return new Result(['Attributes' => self::item($status, $contentType, $expiresAt, $failureReason, $processedAt)]);
    }

    /** @return Result<string, mixed> a ready record */
    public static function ready(string $contentType = 'image/png'): Result
    {
        return self::found('ready', $contentType, null, null, '2026-10-16T11:01:00Z');
    }
}
