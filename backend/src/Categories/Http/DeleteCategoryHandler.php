<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Auth\Session\Principal;
use App\Categories\CategoryNotFound;
use App\Categories\CategoryRepository;
use App\Http\Handler;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\DynamoDb\StaleVersion;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use LogicException;

/**
 * `DELETE /categories/{id}`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the route id
 * (a malformed one is the generic `404`, no storage read), the read (a missing category, another user's included, is
 * the same `404`), the delete. It is unconditional: the repository re-reads and retries once on a version race. A
 * second delete finds nothing and gets `404`. The answer is `204` with an empty body; expenses are not touched (B14).
 *
 * Storage failures are not caught (503 for overload, otherwise a generic 500). A {@see StaleVersion} (the category
 * changed twice during the delete) is not a `409` either, because the contract gives `DELETE` no `409`: it is a
 * generic `500`, and the client can simply try again.
 */
final readonly class DeleteCategoryHandler implements Handler
{
    public function __construct(private CategoryRepository $categories) {}

    /**
     * @throws HttpProblem
     * @throws StaleVersion
     * @throws StorageFailure
     * @throws TransactionCancelled
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = $request->attribute(Principal::ATTRIBUTE);
        if (!$principal instanceof Principal) {
            throw new LogicException('DELETE /categories/{id} must be wrapped in RequireSession.');
        }
        $id = RouteId::uuidOrNotFound($request);

        $current = $this->categories->find($principal, $id) ?? throw HttpProblem::of(404, RouteId::NOT_FOUND_DETAIL);
        try {
            $this->categories->delete($principal, $current);
        } catch (CategoryNotFound) {
            throw HttpProblem::of(404, RouteId::NOT_FOUND_DETAIL);
        }

        return Response::empty(204);
    }
}
