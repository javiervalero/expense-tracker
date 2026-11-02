<?php

declare(strict_types=1);

namespace App\Tests\Integration\Reports;

use App\Auth\Session\Principal;
use App\Categories\CategoryRepository;
use App\Categories\CategoryResolver;
use App\Expenses\ExpenseRepository;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Reports\MonthlyReport;
use App\Reports\MonthlyReportBuilder;
use App\Reports\ReportMonth;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use PHPUnit\Framework\TestCase;

/**
 * The cap of the monthly report (D5) over the real repository on DynamoDB Local, on a table of its own. Production wiring
 * has no test hook, so the cap is lowered through the builder's constructor instead of over HTTP (the HTTP path is
 * MonthlyReportApiTest). Ids and amounts are invented.
 */
final class MonthlyReportCapTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const SEEDED = 30;
    private const LOWERED_CAP = 25;

    private TemporaryTable $table;
    private ItemStore $store;
    private ExpenseRepository $expenses;

    protected function setUp(): void
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $this->table = TemporaryTable::create($client);
        $this->store = new ItemStore($client, $this->table->name);
        $this->expenses = new ExpenseRepository($this->store);
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    private static function owner(): Principal
    {
        return new Principal(self::USER, str_repeat('a', 64));
    }

    /** Seeds `$count` expenses of 2026-10, one every day-ish; expense n costs 1000 + n cents. */
    private function seed(int $count): void
    {
        for ($n = 1; $n <= $count; ++$n) {
            $this->expenses->create(self::owner(), ExpenseBuilder::expense($n, '2026-10-' . sprintf('%02d', 1 + $n % 28)));
        }
    }

    /** @param int<1, max> $cap */
    private function report(int $cap): MonthlyReport
    {
        $month = ReportMonth::parse('2026-10');
        self::assertInstanceOf(ReportMonth::class, $month);
        $resolver = new CategoryResolver(new CategoryRepository($this->store), self::owner());

        return (new MonthlyReportBuilder($this->expenses, $cap))->build(self::owner(), $month, $resolver);
    }

    public function testAMonthAboveTheCapIsIncompleteAndCountsExactlyTheCap(): void
    {
        $this->seed(self::SEEDED);

        $report = $this->report(self::LOWERED_CAP);

        self::assertFalse($report->complete);
        self::assertSame(self::LOWERED_CAP, $report->expenseCount);
        self::assertSame(self::LOWERED_CAP, array_sum(array_map(static fn($row): int => $row->expenseCount, $report->categories)));
    }

    public function testAMonthExactlyAtTheCapIsComplete(): void
    {
        $this->seed(self::LOWERED_CAP);

        $report = $this->report(self::LOWERED_CAP);

        self::assertTrue($report->complete);
        self::assertSame(self::LOWERED_CAP, $report->expenseCount);
    }

    public function testAMonthBelowTheCapIsComplete(): void
    {
        $this->seed(self::LOWERED_CAP - 1);

        $report = $this->report(self::LOWERED_CAP);

        self::assertTrue($report->complete);
        self::assertSame(self::LOWERED_CAP - 1, $report->expenseCount);
    }
}
