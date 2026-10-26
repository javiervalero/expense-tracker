<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Auth\Session\Principal;
use App\Expenses\Http\ListExpensesHandler;
use App\Expenses\Listing\ExpenseLister;
use App\Http\Response;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\FakeExpenseRangeReader;
use App\Tests\Support\ScriptedCategoryStore as Store;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListExpensesHandlerTest extends TestCase
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
    private function list(FakeExpenseRangeReader $reader, array $query = [], ?Principal $principal = null): Response
    {
        return $this->store->serve(
            new ListExpensesHandler(new ExpenseLister($reader), $this->store->repository()),
            Store::request('GET', '/api/v1/expenses', principal: $principal ?? Store::owner(), query: $query),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decoded(Response $response): array
    {
        $body = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @return list<array<string, mixed>> the items of a decoded page
     */
    private static function itemsOf(Response $response): array
    {
        $items = self::decoded($response)['items'] ?? null;
        self::assertIsArray($items);

        /** @var list<array<string, mixed>> $items */
        return $items;
    }

    public function testItAnswers200WithItemsAndTheNextCursorMappedAsTheEightFields(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(3, '2026-10-21'), ExpenseBuilder::expense(2, '2026-10-20'), ExpenseBuilder::expense(1, '2026-10-19')]);

        $response = $this->list($reader, ['limit' => '2']);
        $body = self::decoded($response);

        self::assertSame(200, $response->status);
        self::assertSame(['items', 'nextCursor'], array_keys($body));
        $items = self::itemsOf($response);
        self::assertCount(2, $items);
        self::assertSame(['id', 'date', 'amountCents', 'description', 'categoryId', 'version', 'createdAt', 'updatedAt'], array_keys($items[0]));
        self::assertSame([ExpenseBuilder::uuid(3), ExpenseBuilder::uuid(2)], array_column($items, 'id'));
        self::assertSame(1003, $items[0]['amountCents']);
        self::assertIsString($body['nextCursor']);
        self::assertSame([], $this->store->sent());
    }

    public function testTheLastPageHasANullNextCursorAndAnEmptyListIsStillAPage(): void
    {
        $response = $this->list(new FakeExpenseRangeReader([]));

        self::assertSame(200, $response->status);
        self::assertSame('{"items":[],"nextCursor":null}', $response->body);
    }

    public function testTheFiltersOfTheQueryReachTheReader(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(3, '2026-10-20', 'Coffee'), ExpenseBuilder::expense(2, '2026-10-20', 'Lunch'), ExpenseBuilder::expense(1, '2026-09-30', 'Lunch')]);

        $response = $this->list($reader, ['q' => 'LUNCH', 'from' => '2026-10-01', 'to' => '2026-10-31']);

        self::assertSame([ExpenseBuilder::uuid(2)], array_column(self::itemsOf($response), 'id'));
        self::assertNull(self::decoded($response)['nextCursor']);
        self::assertSame(['2026-10-01', '2026-10-31'], [$reader->lastFrom, $reader->lastTo]);
    }

    public function testACategoryOfTheCallerIsResolvedAndADeletedOneReadsAsNull(): void
    {
        $gone = '1b2c3d4e-5f60-4718-8293-a4b5c6d7e8f9';
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(2, categoryId: self::FOOD), ExpenseBuilder::expense(1, categoryId: $gone)]);
        $this->store->answer(Store::listing([Store::category(self::FOOD, 'Food')]));

        $items = self::itemsOf($this->list($reader));

        self::assertSame([self::FOOD, null], array_column($items, 'categoryId'));
        self::assertSame(['Query'], $this->store->sent());
    }

    public function testTheResolverAndTheListerBothUseThePrincipalOfTheSession(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(1, categoryId: self::FOOD)]);
        $this->store->answer(Store::listing([]));

        $this->list($reader, principal: new Principal(self::OTHER_USER, str_repeat('b', 64)));

        self::assertSame(self::OTHER_USER, $reader->lastOwnerId);
        $values = $this->store->command(0)['ExpressionAttributeValues'];
        self::assertIsArray($values);
        self::assertContains(['S' => 'USER#' . self::OTHER_USER], $values);
    }

    public function testACategoryThatIsNotTheCallersGivesAnEmptyPageWithoutReadingExpenses(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(1, categoryId: self::FOOD)]);
        $this->store->answer(Store::listing([]));

        $response = $this->list($reader, ['category' => self::FOOD]);

        self::assertSame('{"items":[],"nextCursor":null}', $response->body);
        self::assertSame([], $reader->calls);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'bad from' => [['from' => '2026-13-01'], 'The from parameter is not valid.'];
        yield 'bad to' => [['to' => 'yesterday'], 'The to parameter is not valid.'];
        yield 'reversed range' => [['from' => '2026-10-02', 'to' => '2026-10-01'], 'The from parameter must not be after the to parameter.'];
        yield 'bad category' => [['category' => 'food'], 'The category parameter is not valid.'];
        yield 'q with a control character' => [['q' => "a\x01b"], 'The q parameter is not valid.'];
        yield 'q too long' => [['q' => str_repeat('a', 101)], 'The q parameter is not valid.'];
        yield 'limit zero' => [['limit' => '0'], 'The limit parameter is not valid.'];
        yield 'limit above the maximum' => [['limit' => '101'], 'The limit parameter is not valid.'];
        yield 'limit not a number' => [['limit' => '1e2'], 'The limit parameter is not valid.'];
        yield 'garbage cursor' => [['cursor' => 'not-a-cursor'], 'The cursor is not valid for these filters.'];
        yield 'unknown parameter' => [['catgory' => 'none'], 'The query string has a parameter this operation does not accept.'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('invalidQueries')]
    public function testAnInvalidQueryIs400WithAParameterNamingDetailAndNoStoreCall(array $query, string $detail): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(1)]);

        $response = $this->list($reader, $query);
        $body = self::decoded($response);

        self::assertSame(400, $response->status);
        self::assertSame($detail, $body['detail']);
        self::assertSame([], $reader->calls);
        self::assertSame([], $this->store->sent());
    }

    public function testTheDetailNeverEchoesTheValue(): void
    {
        $response = $this->list(new FakeExpenseRangeReader([]), ['from' => 'SECRET-VALUE-1']);

        self::assertSame(400, $response->status);
        self::assertStringNotContainsString('SECRET-VALUE-1', $response->body);
    }

    public function testACursorRefusedByTheReaderIs400(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(2), ExpenseBuilder::expense(1)]);
        $cursor = self::decoded($this->list($reader, ['limit' => '1']))['nextCursor'];
        self::assertIsString($cursor);
        $refusing = new FakeExpenseRangeReader([ExpenseBuilder::expense(2)]);
        $refusing->refuseStartKey = true;

        $response = $this->list($refusing, ['cursor' => $cursor]);

        self::assertSame(400, $response->status);
        self::assertSame('The cursor is not valid for these filters.', self::decoded($response)['detail']);
    }

    public function testOverloadOnTheCategoryReadIsA503(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(1, categoryId: self::FOOD)]);
        $this->store->answerThrottled();

        self::assertSame(503, $this->list($reader)->status);
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingIsRead(): void
    {
        $reader = new FakeExpenseRangeReader([ExpenseBuilder::expense(1)]);
        $handler = new ListExpensesHandler(new ExpenseLister($reader), $this->store->repository());

        try {
            $handler->handle(Store::request('GET', '/x'));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $reader->calls);
            self::assertSame([], $this->store->sent());
        }
    }
}
