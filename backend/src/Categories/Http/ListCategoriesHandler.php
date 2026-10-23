<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Auth\Session\Principal;
use App\Categories\CategoryRepository;
use App\Categories\CategoryResolver;
use App\Http\Handler;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DynamoDb\StorageFailure;
use JsonException;
use LogicException;

/**
 * `GET /categories`, behind {@see \App\Auth\Session\RequireSession}: every category of the principal (at most
 * {@see \App\Categories\CategoryLimits::MAX_PER_USER} in practice), sorted by the resolver's rule (folded name, then
 * id). The partition comes from the principal, never from the request. A storage failure travels on to the
 * StorageUnavailable middleware (503 for overload, otherwise a generic 500).
 */
final readonly class ListCategoriesHandler implements Handler
{
    public function __construct(private CategoryRepository $categories) {}

    /**
     * @throws StorageFailure
     * @throws JsonException
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = $request->attribute(Principal::ATTRIBUTE);
        if (!$principal instanceof Principal) {
            throw new LogicException('GET /categories must be wrapped in RequireSession.');
        }

        return CategoryResponse::list((new CategoryResolver($this->categories, $principal))->all());
    }
}
