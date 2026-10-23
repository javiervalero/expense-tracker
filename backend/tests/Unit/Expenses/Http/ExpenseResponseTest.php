<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Categories\CategoryResolver;
use App\Expenses\Http\ExpenseResponse;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\ScriptedCategoryStore as Store;
use PHPUnit\Framework\TestCase;

final class ExpenseResponseTest extends TestCase
{
    private const CATEGORY = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const KEYS = ['id', 'date', 'amountCents', 'description', 'categoryId', 'version', 'createdAt', 'updatedAt'];

    private function resolver(Store $store): CategoryResolver
    {
        return new CategoryResolver($store->repository(), Store::owner());
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decoded(string $body): array
    {
        $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testTheBodyHasExactlyTheDocumentedKeysInOrder(): void
    {
        $store = new Store();
        $store->answer(Store::listing([Store::category(self::CATEGORY, 'Food')]));

        $response = ExpenseResponse::ok(ExpenseBuilder::expense(7, '2026-10-20', 'Lunch', self::CATEGORY), $this->resolver($store));

        self::assertSame(200, $response->status);
        self::assertSame(self::KEYS, array_keys(self::decoded($response->body)));
        self::assertSame(
            [
                'id' => ExpenseBuilder::uuid(7),
                'date' => '2026-10-20',
                'amountCents' => 1007,
                'description' => 'Lunch',
                'categoryId' => self::CATEGORY,
                'version' => 1,
                'createdAt' => '2026-10-23T10:00:00Z',
                'updatedAt' => '2026-10-23T10:00:00Z',
            ],
            self::decoded($response->body),
        );
    }

    public function testCreatedCarriesTheLocationOfTheExpense(): void
    {
        $response = ExpenseResponse::created(ExpenseBuilder::expense(7), $this->resolver(new Store()));

        self::assertSame(201, $response->status);
        self::assertSame(['/api/v1/expenses/' . ExpenseBuilder::uuid(7)], $response->headerValues('Location'));
        self::assertSame(self::KEYS, array_keys(self::decoded($response->body)));
    }

    public function testAnUncategorizedExpenseHasANullCategoryAndReadsNothing(): void
    {
        $store = new Store();

        $response = ExpenseResponse::ok(ExpenseBuilder::expense(1), $this->resolver($store));

        self::assertNull(self::decoded($response->body)['categoryId']);
        self::assertArrayHasKey('categoryId', self::decoded($response->body));
        self::assertSame([], $store->sent());
    }

    public function testACategoryThatIsNoLongerTheOwnersReadsAsNull(): void
    {
        $store = new Store();
        $store->answer(Store::listing([]));

        $response = ExpenseResponse::ok(ExpenseBuilder::expense(1, categoryId: self::CATEGORY), $this->resolver($store));

        self::assertNull(self::decoded($response->body)['categoryId']);
        self::assertSame(['Query'], $store->sent());
    }
}
