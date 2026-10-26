<?php

declare(strict_types=1);

namespace App\Expenses\Http;

use App\Auth\Session\Principal;
use App\Categories\CategoryLister;
use App\Categories\CategoryResolver;
use App\Expenses\ExpenseRepository;
use App\Http\Handler;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\DynamoDb\StorageFailure;
use JsonException;
use LogicException;

/**
 * `GET /expenses/{id}`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the route id (a
 * malformed one is the generic `404` with no storage read), the read (a missing expense, another user's included, is
 * the same `404`: the key holds the caller's partition, so another user's item cannot be found), the answer. The
 * category goes through the resolver (one category read, only when the expense has a category), built from the same
 * principal as the expense read. Storage failures are not caught; the StorageUnavailable middleware answers them.
 */
final readonly class GetExpenseHandler implements Handler
{
    public function __construct(
        private ExpenseRepository $expenses,
        private CategoryLister $categories,
    ) {}

    /**
     * @throws HttpProblem
     * @throws StorageFailure
     * @throws JsonException
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = Principal::of($request);
        $id = RouteId::uuidOrNotFound($request);

        $expense = $this->expenses->find($principal, $id) ?? throw RouteId::notFound();

        return ExpenseResponse::ok($expense, new CategoryResolver($this->categories, $principal));
    }
}
