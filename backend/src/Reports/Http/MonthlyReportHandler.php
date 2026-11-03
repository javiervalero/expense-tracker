<?php

declare(strict_types=1);

namespace App\Reports\Http;

use App\Auth\Session\Principal;
use App\Categories\CategoryLister;
use App\Categories\CategoryResolver;
use App\Expenses\Listing\InvalidQuery;
use App\Http\Handler;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Reports\MonthlyReportBuilder;
use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * `GET /reports/monthly`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the whole query
 * (an {@see InvalidQuery} is the `400` whose detail never carries the value; nothing is read first), the category resolver
 * for the same principal, the report, the response. The builder checks the pairing of principal and resolver again. Nothing
 * is written. Storage failures are not caught; the StorageUnavailable middleware answers them.
 */
final readonly class MonthlyReportHandler implements Handler
{
    public function __construct(
        private MonthlyReportBuilder $reports,
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
        try {
            $query = MonthlyReportQuery::fromQuery($request->query);
        } catch (InvalidQuery $refused) {
            throw HttpProblem::of(400, $refused->getMessage(), $refused);
        }
        $resolver = new CategoryResolver($this->categories, $principal);

        return MonthlyReportResponse::ok($this->reports->build($principal, $query->month, $resolver));
    }
}
