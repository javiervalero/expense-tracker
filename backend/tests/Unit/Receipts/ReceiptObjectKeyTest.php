<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts;

use App\Receipts\ReceiptObjectKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptObjectKeyTest extends TestCase
{
    private const string USER = '0b3f1c1e-5d2a-4c8e-9a41-2f6d7b8c9d01';
    private const string EXPENSE = '1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12';
    private const string RECEIPT = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';

    public function testBuildsTheOriginalAndThumbnailKeys(): void
    {
        $key = ReceiptObjectKey::from(self::USER, self::EXPENSE, self::RECEIPT);

        self::assertSame('receipts/' . self::USER . '/' . self::EXPENSE . '/' . self::RECEIPT, $key->originalKey());
        self::assertSame(self::USER . '/' . self::EXPENSE . '/' . self::RECEIPT . '.jpg', $key->thumbnailKey());
    }

    public function testParseRoundTripsAndExposesTheIds(): void
    {
        $built = ReceiptObjectKey::from(self::USER, self::EXPENSE, self::RECEIPT);

        $parsed = ReceiptObjectKey::parse($built->originalKey());

        self::assertNotNull($parsed);
        self::assertSame($built->originalKey(), $parsed->originalKey());
        self::assertSame(self::USER, $parsed->userId());
        self::assertSame(self::EXPENSE, $parsed->expenseId());
        self::assertSame(self::RECEIPT, $parsed->receiptId());
    }

    public function testAKeyThatParsesIsReturnedUnchanged(): void
    {
        $key = 'receipts/' . self::USER . '/' . self::EXPENSE . '/' . self::RECEIPT;

        self::assertSame($key, ReceiptObjectKey::parse($key)?->originalKey());
    }

    #[DataProvider('refusedIds')]
    public function testFromRefusesAnInvalidId(string $user, string $expense, string $receipt): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReceiptObjectKey::from($user, $expense, $receipt);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusedIds(): array
    {
        return [
            'user' => ['nope', '1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12', '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23'],
            'expense' => ['0b3f1c1e-5d2a-4c8e-9a41-2f6d7b8c9d01', strtoupper('1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12'), '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23'],
            'receipt' => ['0b3f1c1e-5d2a-4c8e-9a41-2f6d7b8c9d01', '1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12', ''],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedKeys(): array
    {
        $u = '0b3f1c1e-5d2a-4c8e-9a41-2f6d7b8c9d01';
        $e = '1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12';
        $r = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';

        return [
            'upper-case uuid' => ['receipts/' . strtoupper($u) . "/$e/$r"],
            'extra segment' => ["receipts/$u/$e/$r/extra"],
            'dot dot' => ["receipts/$u/../$r"],
            'dot dot in place of an id' => ["receipts/$u/$e/.."],
            'encoded slash left after one decode' => ["receipts/$u/$e%252F$r"],
            'double-encoded key' => ['receipts%252F' . "$u/$e/$r"],
            'trailing slash' => ["receipts/$u/$e/$r/"],
            'missing prefix' => ["$u/$e/$r"],
            'wrong prefix' => ["thumbs/$u/$e/$r"],
            'leading slash' => ["/receipts/$u/$e/$r"],
            'thumbnail key' => ["$u/$e/$r.jpg"],
            'phase 0 pipe test key' => ["receipts/pipe+$u/expense-$u/receipt-$u"],
            'pipe+ prefix on a uuid' => ["receipts/pipe+$u/$e/$r"],
            'encoded first separator (parse never decodes)' => ['receipts%2F' . $u . '/' . $e . '/' . $r],
            'encoded last separator' => ['receipts/' . $u . '/' . $e . '%2F' . $r],
            'encoded letter in the prefix' => ['%72eceipts/' . $u . '/' . $e . '/' . $r],
            'encoded letter in an id' => ['receipts/%30b3f1c1e' . substr($u, 8) . '/' . $e . '/' . $r],
            'empty string' => [''],
            'prefix only' => ['receipts/'],
            'NUL byte' => ["receipts/$u/$e/$r\0"],
            'encoded NUL byte' => ["receipts/$u/$e/$r%00"],
            'nil uuid' => ["receipts/00000000-0000-0000-0000-000000000000/$e/$r"],
            'version 1 uuid' => ["receipts/$u/$e/6ba7b810-9dad-11d1-80b4-00c04fd430c8"],
            'braces' => ['receipts/{' . $u . '}/' . $e . '/' . $r],
            'trailing newline' => ["receipts/$u/$e/$r\n"],
        ];
    }

    #[DataProvider('refusedKeys')]
    public function testParseRefuses(string $key): void
    {
        self::assertNull(ReceiptObjectKey::parse($key));
    }

    public function testTheExpensePrefixesEndWithASlashAndContainTheKeys(): void
    {
        $key = ReceiptObjectKey::from(self::USER, self::EXPENSE, self::RECEIPT);

        $originals = ReceiptObjectKey::originalPrefix(self::USER, self::EXPENSE);
        $thumbnails = ReceiptObjectKey::thumbnailPrefix(self::USER, self::EXPENSE);

        self::assertSame('receipts/' . self::USER . '/' . self::EXPENSE . '/', $originals);
        self::assertSame(self::USER . '/' . self::EXPENSE . '/', $thumbnails);
        self::assertStringStartsWith($originals, $key->originalKey());
        self::assertStringStartsWith($thumbnails, $key->thumbnailKey());
    }

    public function testThePrefixesRefuseAnInvalidId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReceiptObjectKey::originalPrefix(self::USER, '../x');
    }

    public function testTheThumbnailPrefixRefusesAnInvalidId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReceiptObjectKey::thumbnailPrefix('', self::EXPENSE);
    }
}
