<?php

declare(strict_types=1);

namespace App\Expenses\Http;

use App\Auth\Session\Principal;
use App\Categories\CategoryLister;
use App\Categories\CategoryResolver;
use App\Expenses\Listing\ExpenseFilter;
use App\Expenses\Listing\ExpenseLister;
use App\Expenses\Listing\InvalidQuery;
use App\Http\Handler;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DynamoDb\StorageFailure;
use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * `GET /expenses`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the whole query
 * (an {@see InvalidQuery} is the `400` whose detail names the parameter and never its value; nothing is read first), the
 * page. The category resolver is built here from the same principal as the lister call, and the lister checks that
 * pairing again. Nothing is written (a GET never changes state). Storage failures are not caught; the
 * StorageUnavailable middleware answers them.
 */
final readonly class ListExpensesHandler implements Handler
{
    public function __construct(
        private ExpenseLister $expenses,
        private CategoryLister $categories,
    ) {}

    /**
     * @throws HttpProblem
     * @throws InvalidArgumentException when the principal's user id is not a lower-case UUID
     * @throws StorageFailure
     * @throws JsonException
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = Principal::of($request);

        $categories = new CategoryResolver($this->categories, $principal);
        try {
            $filter = ExpenseFilter::fromQuery($request->query);
            $page = $this->expenses->list($principal, $filter, $categories);
        } catch (InvalidQuery $refused) {
            throw HttpProblem::of(400, $refused->getMessage(), $refused);
        }

        return ExpenseResponse::page($page, $categories);
    }
}
