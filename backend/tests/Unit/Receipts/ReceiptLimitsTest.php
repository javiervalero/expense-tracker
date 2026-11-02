<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts;

use App\Receipts\ReceiptLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The limits are owner decisions (plan section 10): changing one must fail here first. */
final class ReceiptLimitsTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function pinned(): array
    {
        return [
            'MAX_BYTES (10 MiB)' => [ReceiptLimits::MAX_BYTES, 10_485_760],
            'MAX_PIXELS' => [ReceiptLimits::MAX_PIXELS, 40_000_000],
            'MAX_SIDE' => [ReceiptLimits::MAX_SIDE, 12_000],
            'POLICY_TTL_SECONDS (5 min)' => [ReceiptLimits::POLICY_TTL_SECONDS, 300],
            'VIEW_URL_TTL_SECONDS (5 min)' => [ReceiptLimits::VIEW_URL_TTL_SECONDS, 300],
            'PENDING_TTL_SECONDS (24 h)' => [ReceiptLimits::PENDING_TTL_SECONDS, 86_400],
            'FAILED_TTL_SECONDS (7 days)' => [ReceiptLimits::FAILED_TTL_SECONDS, 604_800],
        ];
    }

    #[DataProvider('pinned')]
    public function testTheLimitIsPinned(int $actual, int $expected): void
    {
        self::assertSame($expected, $actual);
    }
}
