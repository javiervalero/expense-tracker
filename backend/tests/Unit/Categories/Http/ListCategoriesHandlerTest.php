<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories\Http;

use App\Categories\Http\ListCategoriesHandler;
use App\Tests\Support\ScriptedCategoryStore as Store;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ListCategoriesHandlerTest extends TestCase
{
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const TRAVEL_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const PATH = '/api/v1/categories';

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store();
    }

    private function handler(): ListCategoriesHandler
    {
        return new ListCategoriesHandler($this->store->repository());
    }

    public function testItAnswersTheCategoriesSortedByFoldedName(): void
    {
        $this->store->answer(Store::listing([
            Store::category(self::TRAVEL_ID, 'travel'),
            Store::category(self::FOOD_ID, 'Food'),
        ]));

        $response = $this->store->serve($this->handler(), Store::request('GET', self::PATH, principal: Store::owner()));

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['items'], array_keys($body));
        self::assertIsArray($body['items']);
        self::assertSame([self::FOOD_ID, self::TRAVEL_ID], array_column($body['items'], 'id'));
        self::assertSame(['Query'], $this->store->sent());
    }

    public function testAUserWithNoCategoryGetsAnEmptyList(): void
    {
        $this->store->answer(Store::listing([]));

        $response = $this->store->serve($this->handler(), Store::request('GET', self::PATH, principal: Store::owner()));

        self::assertSame(200, $response->status);
        self::assertSame('{"items":[]}', $response->body);
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingIsRead(): void
    {
        try {
            $this->handler()->handle(Store::request('GET', self::PATH));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->store->sent());
        }
    }

    public function testStorageOverloadIsA503WithRetryAfter(): void
    {
        $this->store->answerThrottled();

        $response = $this->store->serve($this->handler(), Store::request('GET', self::PATH, principal: Store::owner()));

        self::assertSame(503, $response->status);
        self::assertSame(['1'], $response->headerValues('Retry-After'));
    }

    public function testACorruptItemIsAGeneric500(): void
    {
        $this->store->answer(new \Aws\Result(['Items' => [['PK' => ['S' => 'USER#' . Store::USER], 'SK' => ['S' => 'CAT#' . self::FOOD_ID]]]]));

        $response = $this->store->serve($this->handler(), Store::request('GET', self::PATH, principal: Store::owner()));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('CAT#', $response->body);
    }
}
