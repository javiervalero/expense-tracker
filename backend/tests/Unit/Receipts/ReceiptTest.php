<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts;

use App\Receipts\FailureReason;
use App\Receipts\Receipt;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReceiptTest extends TestCase
{
    private const string CREATED = '2026-10-28T12:00:00+00:00';
    private const string NOW = '2026-10-29T12:00:00+00:00';
    private const string RECEIPT_ID = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';

    public function testReadyIsLiveWhateverTheClock(): void
    {
        $receipt = $this->ready();

        self::assertTrue($receipt->isLive(new DateTimeImmutable('2100-01-01T00:00:00+00:00')));
    }

    public function testPendingIsLiveUntilExpiry(): void
    {
        $receipt = $this->pending();

        self::assertTrue($receipt->isLive(new DateTimeImmutable('2026-10-29T11:59:59+00:00')));
        self::assertFalse($receipt->isLive(new DateTimeImmutable(self::NOW)));
        self::assertFalse($receipt->isLive(new DateTimeImmutable('2026-10-29T12:00:01+00:00')));
    }

    public function testFailedIsLiveUntilExpiry(): void
    {
        $receipt = $this->failed();

        self::assertTrue($receipt->isLive(new DateTimeImmutable('2026-10-29T11:59:59+00:00')));
        self::assertFalse($receipt->isLive(new DateTimeImmutable(self::NOW)));
        self::assertFalse($receipt->isLive(new DateTimeImmutable('2026-10-29T12:00:01+00:00')));
    }

    public function testKeepsTheFieldsAndTakesTheReceiptIdFromTheKey(): void
    {
        $receipt = $this->ready();

        self::assertSame(self::RECEIPT_ID, $receipt->receiptId());
        self::assertSame(ReceiptStatus::Ready, $receipt->status);
        self::assertSame(ReceiptContentType::Png, $receipt->contentType);
        self::assertSame(1234, $receipt->size);
        self::assertNull($receipt->failureReason);
        self::assertNull($receipt->expiresAt);
    }

    public function testTheSizeBoundsPass(): void
    {
        self::assertSame(1, $this->pending(size: 1)->size);
        self::assertSame(ReceiptLimits::MAX_BYTES, $this->pending(size: ReceiptLimits::MAX_BYTES)->size);
    }

    public function testRefusesASizeBelowOne(): void
    {
        $this->expectInvalid('A receipt size must be between 1 and 10485760 bytes.');
        $this->pending(size: 0);
    }

    public function testRefusesANegativeSize(): void
    {
        $this->expectInvalid('A receipt size must be between 1 and 10485760 bytes.');
        $this->pending(size: -1);
    }

    public function testRefusesASizeAboveTheLimit(): void
    {
        $this->expectInvalid('A receipt size must be between 1 and 10485760 bytes.');
        $this->pending(size: ReceiptLimits::MAX_BYTES + 1);
    }

    public function testPendingNeedsAnExpiry(): void
    {
        $this->expectInvalid('A pending or failed receipt needs an expiry.');
        $this->build(ReceiptStatus::Pending, null, null, null);
    }

    public function testFailedNeedsAnExpiry(): void
    {
        $this->expectInvalid('A pending or failed receipt needs an expiry.');
        $this->build(ReceiptStatus::Failed, null, FailureReason::TooLarge, $this->at(self::CREATED));
    }

    public function testReadyHasNoExpiry(): void
    {
        $this->expectInvalid('A ready receipt has no expiry.');
        $this->build(ReceiptStatus::Ready, $this->at(self::NOW), null, $this->at(self::CREATED));
    }

    public function testExpiryMustBeAfterCreation(): void
    {
        $this->expectInvalid('A receipt must expire after it was created.');
        $this->build(ReceiptStatus::Pending, $this->at(self::CREATED), null, null);
    }

    public function testExpiryBeforeCreationIsRefused(): void
    {
        $this->expectInvalid('A receipt must expire after it was created.');
        $this->build(ReceiptStatus::Failed, $this->at('2026-10-27T12:00:00+00:00'), FailureReason::Unreadable, $this->at(self::CREATED));
    }

    public function testFailedNeedsAReason(): void
    {
        $this->expectInvalid('A failed receipt needs a failure reason.');
        $this->build(ReceiptStatus::Failed, $this->at(self::NOW), null, $this->at(self::CREATED));
    }

    public function testPendingHasNoReason(): void
    {
        $this->expectInvalid('Only a failed receipt has a failure reason.');
        $this->build(ReceiptStatus::Pending, $this->at(self::NOW), FailureReason::TooLarge, null);
    }

    public function testReadyHasNoReason(): void
    {
        $this->expectInvalid('Only a failed receipt has a failure reason.');
        $this->build(ReceiptStatus::Ready, null, FailureReason::Unreadable, $this->at(self::CREATED));
    }

    public function testPendingHasNotBeenProcessed(): void
    {
        $this->expectInvalid('A receipt has been processed exactly when it is no longer pending.');
        $this->build(ReceiptStatus::Pending, $this->at(self::NOW), null, $this->at(self::CREATED));
    }

    public function testReadyHasBeenProcessed(): void
    {
        $this->expectInvalid('A receipt has been processed exactly when it is no longer pending.');
        $this->build(ReceiptStatus::Ready, null, null, null);
    }

    public function testFailedHasBeenProcessed(): void
    {
        $this->expectInvalid('A receipt has been processed exactly when it is no longer pending.');
        $this->build(ReceiptStatus::Failed, $this->at(self::NOW), FailureReason::Unreadable, null);
    }

    private function expectInvalid(string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    private function pending(int $size = 1234): Receipt
    {
        return $this->build(ReceiptStatus::Pending, $this->at(self::NOW), null, null, $size);
    }

    private function ready(): Receipt
    {
        return $this->build(ReceiptStatus::Ready, null, null, $this->at('2026-10-28T13:00:00+00:00'));
    }

    private function failed(): Receipt
    {
        return $this->build(ReceiptStatus::Failed, $this->at(self::NOW), FailureReason::Unreadable, $this->at('2026-10-28T13:00:00+00:00'));
    }

    private function build(
        ReceiptStatus $status,
        ?DateTimeImmutable $expiresAt,
        ?FailureReason $reason,
        ?DateTimeImmutable $processedAt,
        int $size = 1234,
    ): Receipt {
        return new Receipt(
            ReceiptObjectKey::from('0b3f1c1e-5d2a-4c8e-9a41-2f6d7b8c9d01', '1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12', self::RECEIPT_ID),
            $status,
            ReceiptContentType::Png,
            $size,
            $this->at(self::CREATED),
            $expiresAt,
            $reason,
            $processedAt,
        );
    }
}
