<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories\Http;

use App\Categories\Http\CategoryResponse;
use App\Tests\Support\ScriptedCategoryStore;
use PHPUnit\Framework\TestCase;

final class CategoryResponseTest extends TestCase
{
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const TRAVEL_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';

    public function testACategoryHasExactlyTheFiveDocumentedMembersAndNeverTheNameKey(): void
    {
        $category = ScriptedCategoryStore::category(self::FOOD_ID, 'Food', 4);

        $fields = CategoryResponse::fields($category);

        self::assertSame([
            'id' => self::FOOD_ID,
            'name' => 'Food',
            'version' => 4,
            'createdAt' => '2026-10-23T10:00:00Z',
            'updatedAt' => '2026-10-23T10:00:00Z',
        ], $fields);
        self::assertStringNotContainsString($category->nameKey, (string) json_encode($fields));
    }

    public function testOkIsA200WithTheCategoryAndNoLocation(): void
    {
        $response = CategoryResponse::ok(ScriptedCategoryStore::category(self::FOOD_ID, 'Food'));

        self::assertSame(200, $response->status);
        self::assertSame(['application/json'], $response->headerValues('Content-Type'));
        self::assertSame([], $response->headerValues('Location'));
        self::assertSame('Food', self::decoded($response)['name'] ?? null);
    }

    public function testCreatedIsA201WithTheAddressOfTheCategory(): void
    {
        $response = CategoryResponse::created(ScriptedCategoryStore::category(self::FOOD_ID, 'Food'));

        self::assertSame(201, $response->status);
        self::assertSame(['/api/v1/categories/' . self::FOOD_ID], $response->headerValues('Location'));
    }

    public function testTheListIsItemsInTheGivenOrderAndNothingElse(): void
    {
        $response = CategoryResponse::list([
            ScriptedCategoryStore::category(self::TRAVEL_ID, 'Travel'),
            ScriptedCategoryStore::category(self::FOOD_ID, 'Food'),
        ]);

        self::assertSame(200, $response->status);
        $body = self::decoded($response);
        self::assertSame(['items'], array_keys($body));
        $items = $body['items'];
        self::assertIsArray($items);
        self::assertSame([self::TRAVEL_ID, self::FOOD_ID], array_column($items, 'id'));
        self::assertIsArray($items[0]);
        self::assertSame(['id', 'name', 'version', 'createdAt', 'updatedAt'], array_keys($items[0]));
    }

    public function testAnEmptyListIsAJsonArrayNotAnObject(): void
    {
        self::assertSame('{"items":[]}', CategoryResponse::list([])->body);
    }

    /**
     * @return array<mixed>
     */
    private static function decoded(\App\Http\Response $response): array
    {
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
