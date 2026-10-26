<?php

declare(strict_types=1);

namespace App\Expenses\Http;

use App\Auth\Session\Principal;
use App\Categories\CategoryLister;
use App\Categories\CategoryResolver;
use App\Expenses\Expense;
use App\Expenses\ExpenseNotFound;
use App\Expenses\ExpenseRepository;
use App\Http\Handler;
use App\Http\JsonBody;
use App\Http\Problem\FieldError;
use App\Http\Problem\HttpProblem;
use App\Http\Problem\ValidationProblem;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\StaleVersion;
use App\Infrastructure\DynamoDb\StorageFailure;
use JsonException;
use LogicException;

/**
 * `PATCH /expenses/{id}`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the route id (a
 * malformed one is the generic `404` with no storage read), the body (415, 413, 400, 422: no storage is touched), the
 * read (a missing expense, another user's included, is the same `404`), the version check (`409` when the stored version
 * is not the one sent: no category is read, so a stale request is `409` whatever its `categoryId`), the category check
 * (422 at `/categoryId`, no write), the conditional write (`409` again if another request won the race), the answer.
 *
 * The merged state is the stored expense with the members that are present replaced. A category that is not set by the
 * patch is the stored one run through {@see CategoryResolver::resolve()}, so a leftover id (its category was deleted)
 * is replaced by `null` (ADR 0002). The resolver and the repository share one principal, and the response goes through
 * the same resolver, so the category list is read at most once. A {@see StorageFailure} from the resolver is not
 * caught: it ends the request (503 for overload, otherwise a generic 500) and nothing is written.
 */
final readonly class UpdateExpenseHandler implements Handler
{
    private const string STALE_VERSION = 'The expense changed since you loaded it. Reload it and try again.';

    public function __construct(
        private ExpenseRepository $expenses,
        private CategoryLister $categories,
        private Clock $clock,
    ) {}

    /**
     * @throws HttpProblem
     * @throws ValidationProblem
     * @throws StorageFailure
     * @throws JsonException
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = Principal::of($request);
        $id = RouteId::uuidOrNotFound($request);
        $input = ExpensePatchInput::fromBody(JsonBody::decode($request->header('content-type'), $request->body));

        $current = $this->expenses->find($principal, $id) ?? throw HttpProblem::of(404, RouteId::NOT_FOUND_DETAIL);
        if ($current->version !== $input->version) {
            throw HttpProblem::of(409, self::STALE_VERSION);
        }
        $resolver = new CategoryResolver($this->categories, $principal);
        $categoryId = self::categoryAfter($input, $current, $resolver);

        $merged = $current->updated(
            $input->date ?? $current->date,
            $input->amount ?? $current->amount,
            $input->description ?? $current->description,
            $categoryId,
            $this->clock->now(),
        );
        try {
            $saved = $this->expenses->update($principal, $merged, $input->version);
        } catch (StaleVersion) {
            throw HttpProblem::of(409, self::STALE_VERSION);
        } catch (ExpenseNotFound) {
            throw HttpProblem::of(404, RouteId::NOT_FOUND_DETAIL);
        }

        return ExpenseResponse::ok($saved, $resolver);
    }

    /**
     * @throws ValidationProblem when the patch sets a category that is not one of the caller's
     * @throws StorageFailure
     */
    private static function categoryAfter(ExpensePatchInput $input, Expense $current, CategoryResolver $resolver): ?string
    {
        if (!$input->setsCategory) {
            return $resolver->resolve($current->categoryId);
        }
        if ($input->categoryId !== null && !$resolver->has($input->categoryId)) {
            throw new ValidationProblem([new FieldError('/categoryId', 'Must be the id of one of your categories, or null.')]);
        }

        return $input->categoryId;
    }
}
