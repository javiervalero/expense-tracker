<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Expenses\Http\GetExpenseHandler;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\ScriptedCategoryStore as Store;
use LogicException;
use PHPUnit\Framework\TestCase;

final class GetExpenseHandlerTest extends TestCase
{
    private const FOOD = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store();
    }

    private function get(string $id): Response
    {
        return $this->store->serve(
            new GetExpenseHandler($this->store->expenses(), $this->store->repository()),
            Store::request('GET', '/api/v1/expenses/' . $id, principal: Store::owner(), routeParams: ['id' => $id]),
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

    public function testAnUncategorizedExpenseIsReturnedWithOneRead(): void
    {
        $this->store->answer(Store::expenseFound(ExpenseBuilder::expense(3)));

        $response = $this->get(ExpenseBuilder::uuid(3));

        self::assertSame(200, $response->status);
        self::assertSame(['id', 'date', 'amountCents', 'description', 'categoryId', 'version', 'createdAt', 'updatedAt'], array_keys(self::decoded($response)));
        self::assertSame(ExpenseBuilder::uuid(3), self::decoded($response)['id']);
        self::assertNull(self::decoded($response)['categoryId']);
        self::assertSame(['GetItem'], $this->store->sent());
    }

    public function testACategoryOfTheCallerIsReturnedAfterOneCategoryRead(): void
    {
        $this->store->answer(Store::expenseFound(ExpenseBuilder::expense(3, categoryId: self::FOOD)), Store::listing([Store::category(self::FOOD, 'Food')]));

        $response = $this->get(ExpenseBuilder::uuid(3));

        self::assertSame(self::FOOD, self::decoded($response)['categoryId']);
        self::assertSame(['GetItem', 'Query'], $this->store->sent());
    }

    public function testACategoryDeletedAfterTheCreateReadsBackAsNull(): void
    {
        $this->store->answer(Store::expenseFound(ExpenseBuilder::expense(3, categoryId: self::FOOD)), Store::listing([]));

        $response = $this->get(ExpenseBuilder::uuid(3));

        self::assertSame(200, $response->status);
        self::assertNull(self::decoded($response)['categoryId']);
    }

    public function testAnUnknownIdIsTheGeneric404(): void
    {
        $this->store->answer(Store::missing());

        $response = $this->get(ExpenseBuilder::uuid(9));

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
        self::assertSame(['GetItem'], $this->store->sent());
    }

    public function testAMalformedIdIsTheSame404BodyWithZeroStorageReads(): void
    {
        $this->store->answer(Store::missing());
        $unknown = $this->get(ExpenseBuilder::uuid(9));
        $other = new Store();

        $response = $other->serve(
            new GetExpenseHandler($other->expenses(), $other->repository()),
            Store::request('GET', '/x', principal: Store::owner(), routeParams: ['id' => 'nope']),
        );

        self::assertSame(404, $response->status);
        self::assertSame($unknown->body, $response->body);
        self::assertSame([], $other->sent());
    }

    public function testOverloadOnTheReadIsA503(): void
    {
        $this->store->answerThrottled();

        self::assertSame(503, $this->get(ExpenseBuilder::uuid(3))->status);
    }

    public function testOverloadOnTheCategoryReadIsA503(): void
    {
        $this->store->answer(Store::expenseFound(ExpenseBuilder::expense(3, categoryId: self::FOOD)));
        $this->store->answerThrottled();

        self::assertSame(503, $this->get(ExpenseBuilder::uuid(3))->status);
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingIsRead(): void
    {
        $handler = new GetExpenseHandler($this->store->expenses(), $this->store->repository());

        try {
            $handler->handle(Store::request('GET', '/x', routeParams: ['id' => ExpenseBuilder::uuid(3)]));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->store->sent());
        }
    }
}
