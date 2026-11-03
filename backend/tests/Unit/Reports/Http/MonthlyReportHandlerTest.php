<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Http;

use App\Auth\Session\Principal;
use App\Http\Response;
use App\Reports\Http\MonthlyReportHandler;
use App\Reports\MonthlyReportBuilder;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\FakeExpenseRangeReader;
use App\Tests\Support\ScriptedCategoryStore as Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The handler of `GET /reports/monthly`: principal, then the whole query (a refusal reads nothing), then the report.
 */
final class MonthlyReportHandlerTest extends TestCase
{
    private const FOOD = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const OTHER_USER = '7a6b5c4d-3e2f-4a1b-8c9d-0e1f2a3b4c5d';

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store();
    }

    /**
     * @param array<string, string> $query
     */
    private function report(FakeExpenseRangeReader $reader, array $query, ?Principal $principal = null): Response
    {
        return $this->store->serve(
            new MonthlyReportHandler(new MonthlyReportBuilder($reader), $this->store->repository()),
            Store::request('GET', '/api/v1/reports/monthly', principal: $principal ?? Store::owner(), query: $query),
        );
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function refusedQueries(): iterable
    {
        yield 'missing month' => [[]];
        yield 'empty month' => [['month' => '']];
        yield 'invalid month' => [['month' => '2026-13']];
        yield 'unknown parameter' => [['month' => '2026-10', 'from' => '2026-10-01']];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('refusedQueries')]
    public function testARefusedQueryIs400AndNothingIsRead(array $query): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(1)]);

        $response = $this->report($reader, $query);

        self::assertSame(400, $response->status);
        self::assertSame([], $reader->calls);
        self::assertSame([], $this->store->sent());
    }

    public function testAValidMonthIsReportedFromTheCallersData(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(2, '2026-10-21', categoryId: self::FOOD, cents: 300), ExpenseBuilder::expense(1, '2026-10-20', cents: 200)]);
        $this->store->answer(Store::listing([Store::category(self::FOOD, 'Food')]));

        $response = $this->report($reader, ['month' => '2026-10']);

        self::assertSame(200, $response->status);
        self::assertSame(
            '{"month":"2026-10","totalCents":500,"expenseCount":2,"complete":true,"categories":['
            . '{"categoryId":"' . self::FOOD . '","name":"Food","totalCents":300,"expenseCount":1},'
            . '{"categoryId":null,"name":null,"totalCents":200,"expenseCount":1}]}',
            $response->body,
        );
        self::assertSame(['2026-10-01', '2026-10-31'], [$reader->lastFrom, $reader->lastTo]);
    }

    public function testTheReaderAndTheResolverBothUseThePrincipalOfTheSession(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(1, categoryId: self::FOOD)]);
        $this->store->answer(Store::listing([]));

        $this->report($reader, ['month' => '2026-10'], new Principal(self::OTHER_USER, str_repeat('b', 64)));

        self::assertSame(self::OTHER_USER, $reader->lastOwnerId);
        $values = $this->store->command(0)['ExpressionAttributeValues'];
        self::assertIsArray($values);
        self::assertContains(['S' => 'USER#' . self::OTHER_USER], $values);
    }

    public function testAnEmptyMonthDoesNotReadTheCategories(): void
    {
        $response = $this->report(new FakeExpenseRangeReader([]), ['month' => '2026-10']);

        self::assertSame('{"month":"2026-10","totalCents":0,"expenseCount":0,"complete":true,"categories":[]}', $response->body);
        self::assertSame([], $this->store->sent());
    }

    public function testOverloadOnTheCategoryReadIsA503(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(1, categoryId: self::FOOD)]);
        $this->store->answerThrottled();

        self::assertSame(503, $this->report($reader, ['month' => '2026-10'])->status);
    }
}
