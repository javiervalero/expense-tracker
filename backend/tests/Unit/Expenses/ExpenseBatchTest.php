<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses;

use App\Expenses\ExpenseBatch;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Tests\Support\ExpenseBuilder;
use PHPUnit\Framework\TestCase;

/** The two guards every reader of a batch applies (B18): never more than asked for, never empty with a key. */
final class ExpenseBatchTest extends TestCase
{
    private const KEY = ['SK' => 'EXP#a', 'LSI1SK' => '2026-10-20#a'];

    public function testABatchWithinTheLimitIsReturnedUnchanged(): void
    {
        $batch = new ExpenseBatch([ExpenseBuilder::expense(1), ExpenseBuilder::expense(2)], self::KEY);

        self::assertSame($batch, $batch->checkedAgainst(2));
    }

    public function testAnEmptyBatchWithoutAKeyIsAccepted(): void
    {
        $batch = new ExpenseBatch([], null);

        self::assertSame($batch, $batch->checkedAgainst(1));
    }

    public function testMoreItemsThanAskedForIsCorruptStorage(): void
    {
        $batch = new ExpenseBatch([ExpenseBuilder::expense(1), ExpenseBuilder::expense(2)], null);

        $this->expectException(StorageFailure::class);
        $this->expectExceptionMessage(ItemStore::QUERY_OPERATION);
        $batch->checkedAgainst(1);
    }

    public function testAnEmptyBatchWithAContinuationKeyIsCorruptStorage(): void
    {
        $batch = new ExpenseBatch([], self::KEY);

        $this->expectException(StorageFailure::class);
        $this->expectExceptionMessage(StorageFailure::CORRUPT_ITEM);
        $batch->checkedAgainst(10);
    }
}
