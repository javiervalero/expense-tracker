<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryLimits;
use App\Categories\CategoryNameTaken;
use App\Categories\CategoryNotFound;
use App\Categories\CategoryRepository;
use App\Categories\CategoryResolver;
use App\Http\Handler;
use App\Http\JsonBody;
use App\Http\Problem\HttpProblem;
use App\Http\Problem\ValidationProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\Id\IdGenerator;
use JsonException;
use LogicException;

/**
 * `POST /categories`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the body (415, 413,
 * 400, 422), the cap, the write (409 for a taken name).
 *
 * The cap of {@see CategoryLimits::MAX_PER_USER} (B8) cannot be a database rule, so it is kept in two steps:
 *
 *  1. before creating, the user's categories are counted (the resolver's list); at the cap the answer is `409`
 *     (limit reached) and nothing is written;
 *  2. parallel creates can each pass step 1, so after a successful create the categories are counted again with a
 *     fresh read (not the memoised resolver). If there are now more than the cap, the handler deletes the category it
 *     just created and answers the same `409`.
 *
 * So the count can exceed the cap only while a racing request is in flight and never stays above it. Under a race
 * several creators may all back out: that false refusal at the cap is accepted. A compensating delete that finds the
 * category already gone ({@see CategoryNotFound}) still ends in the `409`, because the aim is met. Any other failure of
 * the compensating delete is not swallowed: it travels on (503 for overload, otherwise a generic 500, a
 * {@see \App\Infrastructure\DynamoDb\StaleVersion} included), and the extra category stays: the user is above the
 * cap until they delete enough categories to be at or below it, and every create is refused at step 1 meanwhile.
 *
 * The one case where the overshoot can persist without any failure being reported: a create that fails ambiguously
 * (a timeout or a 5xx after the transaction committed) throws before the recount, so nothing backs it out.
 *
 * Storage failures and cancelled transactions are not caught here; the StorageUnavailable middleware answers them.
 */
final readonly class CreateCategoryHandler implements Handler
{
    public function __construct(
        private CategoryRepository $categories,
        private IdGenerator $ids,
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
        $name = CategoryInput::newCategory(JsonBody::decode($request->header('content-type'), $request->body));

        if (count((new CategoryResolver($this->categories, $principal))->all()) >= CategoryLimits::MAX_PER_USER) {
            throw CategoryConflict::limitReached();
        }
        $category = Category::new($this->ids->generate(), $name, $this->clock->now());
        try {
            $this->categories->create($principal, $category);
        } catch (CategoryNameTaken) {
            throw CategoryConflict::nameTaken();
        }
        if (count($this->categories->listAll($principal)) > CategoryLimits::MAX_PER_USER) {
            try {
                $this->categories->delete($principal, $category);
            } catch (CategoryNotFound) {
                // Already gone: the aim of the back-out (no category above the cap) is met.
            }

            throw CategoryConflict::limitReached();
        }

        return CategoryResponse::created($category);
    }
}
