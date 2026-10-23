<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Infrastructure\Id\Uuid;
use LogicException;

/**
 * Reads an id from the route parameters (B9). Anything but a lower-case canonical v4 UUID gets the same generic `404`
 * as an unknown or someone else's resource (B10), before any storage is read, so a malformed id costs nothing and
 * tells nothing. Call it after {@see \App\Auth\Session\RequireSession}: an anonymous caller gets `401` first.
 */
final class RouteId
{
    public const string NOT_FOUND_DETAIL = 'The resource does not exist.';

    /**
     * @throws HttpProblem 404 when the id is malformed
     * @throws LogicException when the route has no such parameter (a route template and handler that disagree)
     */
    public static function uuidOrNotFound(Request $request, string $name = 'id'): string
    {
        $params = $request->attribute(Request::ROUTE_PARAMS);
        $value = is_array($params) ? ($params[$name] ?? null) : null;
        if (!is_string($value)) {
            throw new LogicException('The route has no parameter "' . $name . '".');
        }
        if (!Uuid::isValid($value)) {
            throw HttpProblem::of(404, self::NOT_FOUND_DETAIL);
        }

        return $value;
    }
}
