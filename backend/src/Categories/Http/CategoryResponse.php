<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Categories\Category;
use App\Http\Response;
use App\Infrastructure\Time\StoredTime;
use JsonException;

/**
 * The bodies of the category endpoints (B20). A category is mapped field by field to exactly `{id, name, version,
 * createdAt, updatedAt}`, so `nameKey` and any key of the storage never leave the server, whatever the entity grows.
 */
final class CategoryResponse
{
    private const LOCATION_PREFIX = '/api/v1/categories/';

    private function __construct() {}

    /**
     * @return array{id: string, name: string, version: int, createdAt: string, updatedAt: string}
     */
    public static function fields(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name->value(),
            'version' => $category->version,
            'createdAt' => StoredTime::format($category->createdAt),
            'updatedAt' => StoredTime::format($category->updatedAt),
        ];
    }

    /**
     * @throws JsonException
     */
    public static function ok(Category $category): Response
    {
        return Response::json(200, self::fields($category));
    }

    /**
     * @throws JsonException
     */
    public static function created(Category $category): Response
    {
        return Response::json(201, self::fields($category), ['Location' => [self::LOCATION_PREFIX . $category->id]]);
    }

    /**
     * @param list<Category> $categories in the order to show
     *
     * @throws JsonException
     */
    public static function list(array $categories): Response
    {
        return Response::json(200, ['items' => array_map(self::fields(...), $categories)]);
    }
}
