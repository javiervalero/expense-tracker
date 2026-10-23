<?php

declare(strict_types=1);

namespace App\Expenses\Http;

use App\Categories\CategoryResolver;
use App\Expenses\Expense;
use App\Http\Response;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Time\StoredTime;
use InvalidArgumentException;
use JsonException;

/**
 * The bodies of the expense endpoints (B20). An expense is mapped field by field to exactly `{id, date, amountCents,
 * description, categoryId, version, createdAt, updatedAt}`, so no storage key leaves the server, whatever the entity
 * grows. `categoryId` always goes through {@see CategoryResolver::resolve()}: a category deleted since the write (or
 * one that is not the caller's) reads as `null`, and the member is always present.
 */
final class ExpenseResponse
{
    private const string LOCATION_PREFIX = '/api/v1/expenses/';

    private function __construct() {}

    /**
     * @return array{id: string, date: string, amountCents: int, description: string, categoryId: string|null, version: int, createdAt: string, updatedAt: string}
     *
     * @throws InvalidArgumentException when the resolver's owner has no valid user id
     * @throws StorageFailure
     */
    public static function fields(Expense $expense, CategoryResolver $categories): array
    {
        return [
            'id' => $expense->id,
            'date' => $expense->date->value(),
            'amountCents' => $expense->amount->cents(),
            'description' => $expense->description->value(),
            'categoryId' => $categories->resolve($expense->categoryId),
            'version' => $expense->version,
            'createdAt' => StoredTime::format($expense->createdAt),
            'updatedAt' => StoredTime::format($expense->updatedAt),
        ];
    }

    /**
     * @throws JsonException
     * @throws StorageFailure
     */
    public static function ok(Expense $expense, CategoryResolver $categories): Response
    {
        return Response::json(200, self::fields($expense, $categories));
    }

    /**
     * @throws JsonException
     * @throws StorageFailure
     */
    public static function created(Expense $expense, CategoryResolver $categories): Response
    {
        return Response::json(201, self::fields($expense, $categories), ['Location' => [self::LOCATION_PREFIX . $expense->id]]);
    }
}
