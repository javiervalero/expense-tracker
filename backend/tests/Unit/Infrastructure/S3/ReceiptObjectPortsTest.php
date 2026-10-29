<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\S3;

use App\Infrastructure\S3\S3OriginalObjects;
use App\Infrastructure\S3\S3ThumbnailObjects;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectKey;
use App\Tests\Support\S3\InMemoryObjectStore;
use App\Tests\Support\S3\TestS3Settings;
use PHPUnit\Framework\TestCase;

final class ReceiptObjectPortsTest extends TestCase
{
    private const USER = '0b3f1c1e-5d2a-4c8e-9a41-2f6d7b8c9d01';
    private const EXPENSE = '1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12';
    private const RECEIPT = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';

    private function key(): ReceiptObjectKey
    {
        return ReceiptObjectKey::from(self::USER, self::EXPENSE, self::RECEIPT);
    }

    public function testOriginalsReadAndDeleteInTheReceiptsBucketOnly(): void
    {
        $store = new InMemoryObjectStore();
        $store->objects['rcp'][$this->key()->originalKey()] = 'bytes';
        $originals = new S3OriginalObjects($store, TestS3Settings::create());

        self::assertSame('bytes', $originals->get($this->key()));
        $originals->delete($this->key());

        self::assertSame([], $store->keys('rcp'));
        self::assertSame(['get rcp ' . $this->key()->originalKey(), 'delete rcp ' . $this->key()->originalKey()], $store->calls);
    }

    public function testOriginalsDeleteAnExpenseByItsPrefix(): void
    {
        $store = new InMemoryObjectStore();
        $store->objects['rcp']['receipts/' . self::USER . '/' . self::EXPENSE . '/a'] = '1';
        $store->objects['rcp']['receipts/' . self::USER . '/' . self::EXPENSE . '0/a'] = 'sibling';

        (new S3OriginalObjects($store, TestS3Settings::create()))->deleteExpense(self::USER, self::EXPENSE);

        self::assertSame(['deletePrefix rcp receipts/' . self::USER . '/' . self::EXPENSE . '/'], $store->calls);
        self::assertSame(['receipts/' . self::USER . '/' . self::EXPENSE . '0/a' => 'sibling'], $store->objects['rcp']);
    }

    public function testThumbnailsWriteAJpegAndDeleteInTheThumbnailsBucketOnly(): void
    {
        $store = new InMemoryObjectStore();
        $thumbnails = new S3ThumbnailObjects($store, TestS3Settings::create());

        $thumbnails->put($this->key(), 'jpeg');
        self::assertSame(['thumbs' => [$this->key()->thumbnailKey() => 'jpeg']], $store->objects);
        $thumbnails->delete($this->key());

        self::assertSame([], $store->keys('thumbs'));
    }

    public function testThumbnailsDeleteAnExpenseByItsPrefix(): void
    {
        $store = new InMemoryObjectStore();

        (new S3ThumbnailObjects($store, TestS3Settings::create()))->deleteExpense(self::USER, self::EXPENSE);

        self::assertSame(['deletePrefix thumbs ' . self::USER . '/' . self::EXPENSE . '/'], $store->calls);
    }

    public function testOriginalsAlwaysReadWithTheUploadSizeLimit(): void
    {
        $store = new InMemoryObjectStore();

        (new S3OriginalObjects($store, TestS3Settings::create()))->get($this->key());

        self::assertSame(ReceiptLimits::MAX_BYTES, $store->lastMaxBytes);
    }
}
