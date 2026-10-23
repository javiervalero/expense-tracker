<?php

declare(strict_types=1);

namespace App\Expenses\Http;

use App\Auth\Session\Principal;
use App\Categories\CategoryLister;
use App\Categories\CategoryResolver;
use App\Expenses\Expense;
use App\Expenses\ExpenseRepository;
use App\Http\Handler;
use App\Http\JsonBody;
use App\Http\Problem\FieldError;
use App\Http\Problem\HttpProblem;
use App\Http\Problem\ValidationProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Id\IdGenerator;
use JsonException;
use LogicException;

/**
 * `POST /expenses`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the body (415, 413,
 * 400, 422: no storage is touched), the category check (422 at `/categoryId` unless it is absent, `null` or one of the
 * caller's categories; only a well-formed id costs a read), the write, the answer.
 *
 * The resolver and the repository call share one principal, and the response goes through the same resolver, so the
 * category list is read at most once. A {@see StorageFailure} from the resolver is not caught (no per-item recovery):
 * it ends the request at the middleware (503 for overload, otherwise a generic 500) and nothing is written. A category
 * deleted between the check and the write leaves a leftover id on the expense, which reads back as `null` (B16), by design.
 */
final readonly class CreateExpenseHandler implements Handler
{
    public function __construct(
        private ExpenseRepository $expenses,
        private CategoryLister $categories,
        private IdGenerator $ids,
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
        $input = NewExpenseInput::fromBody(JsonBody::decode($request->header('content-type'), $request->body));
        $resolver = new CategoryResolver($this->categories, $principal);
        if ($input->categoryId !== null && !$resolver->has($input->categoryId)) {
            throw new ValidationProblem([new FieldError('/categoryId', 'Must be the id of one of your categories, or null.')]);
        }

        $expense = Expense::new($this->ids->generate(), $input->date, $input->amount, $input->description, $input->categoryId, $this->clock->now());
        $this->expenses->create($principal, $expense);

        return ExpenseResponse::created($expense, $resolver);
    }
}
