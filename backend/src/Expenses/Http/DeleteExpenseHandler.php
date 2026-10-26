<?php

declare(strict_types=1);

namespace App\Expenses\Http;

use App\Auth\Session\Principal;
use App\Expenses\ExpenseNotFound;
use App\Expenses\ExpenseRepository;
use App\Http\Handler;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use LogicException;

/**
 * `DELETE /expenses/{id}`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the route id
 * (a malformed one is the generic `404`, no storage call), the delete. It is unconditional and needs no read first:
 * the repository's transaction fails on a missing expense (another user's included, which the key cannot reach), and
 * that is the same `404`. A second delete finds nothing and gets `404`. The answer is `204` with an empty body.
 *
 * Storage failures are not caught (503 for overload, otherwise a generic 500).
 */
final readonly class DeleteExpenseHandler implements Handler
{
    public function __construct(private ExpenseRepository $expenses) {}

    /**
     * @throws HttpProblem
     * @throws StorageFailure
     * @throws TransactionCancelled
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = Principal::of($request);
        $id = RouteId::uuidOrNotFound($request);
        try {
            $this->expenses->delete($principal, $id);
        } catch (ExpenseNotFound) {
            throw HttpProblem::of(404, RouteId::NOT_FOUND_DETAIL);
        }

        return Response::empty(204);
    }
}
