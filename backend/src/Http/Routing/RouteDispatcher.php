<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Http\Handler;
use App\Http\Request;
use App\Http\Problem\HttpProblem;
use App\Http\Response;

/**
 * The end of the middleware chain: matches the request with the {@see Router} and calls the route's handler with
 * the decoded parameters in the {@see Request::ROUTE_PARAMS} attribute, or answers the router's 404 or 405.
 */
final readonly class RouteDispatcher implements Handler
{
    public function __construct(private Router $router) {}

    public function handle(Request $request): Response
    {
        // Fail closed even if the pipeline was assembled without DeferredProblemMiddleware.
        $deferred = $request->attribute(Request::DEFERRED_PROBLEM);
        if ($deferred instanceof HttpProblem) {
            throw $deferred;
        }

        $result = $this->router->match($request);
        $route = $result->route;
        if ($route === null) {
            return $result->problemResponse();
        }

        return $route->handler()->handle($request->withAttributes([Request::ROUTE_PARAMS => $result->params]));
    }
}
