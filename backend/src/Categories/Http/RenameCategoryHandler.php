<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Auth\Session\Principal;
use App\Categories\CategoryNameTaken;
use App\Categories\CategoryNotFound;
use App\Categories\CategoryRepository;
use App\Http\Handler;
use App\Http\JsonBody;
use App\Http\Problem\HttpProblem;
use App\Http\Problem\ValidationProblem;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\StaleVersion;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use JsonException;
use LogicException;

/**
 * `PATCH /categories/{id}`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the route id
 * (a malformed one is the generic `404` with no storage read), the body (415, 413, 400, 422), the read (a missing
 * category, also another user's, is the same `404`), the conditional write (`409` for a taken name or a stale version).
 *
 * Only the repository's domain outcomes become responses. A {@see TransactionCancelled} or {@see StorageFailure} is not
 * caught: overload becomes `503` in the StorageUnavailable middleware, and anything else (a raw
 * `ConditionalCheckFailed` cancellation included) a generic `500`, never a `409`.
 */
final readonly class RenameCategoryHandler implements Handler
{
    public function __construct(
        private CategoryRepository $categories,
        private Clock $clock,
    ) {}

    /**
     * @throws HttpProblem
     * @throws ValidationProblem
     * @throws StorageFailure
     * @throws TransactionCancelled
     * @throws JsonException
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = Principal::of($request);
        $id = RouteId::uuidOrNotFound($request);
        $input = CategoryInput::rename(JsonBody::decode($request->header('content-type'), $request->body));

        $current = $this->categories->find($principal, $id) ?? throw HttpProblem::of(404, RouteId::NOT_FOUND_DETAIL);
        try {
            $renamed = $this->categories->rename($principal, $current, $input->name, $input->version, $this->clock->now());
        } catch (CategoryNameTaken) {
            throw CategoryConflict::nameTaken();
        } catch (StaleVersion) {
            throw CategoryConflict::staleVersion();
        } catch (CategoryNotFound) {
            throw HttpProblem::of(404, RouteId::NOT_FOUND_DETAIL);
        }

        return CategoryResponse::ok($renamed);
    }
}
