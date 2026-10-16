<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;

/**
 * Raises the problem of a request that could not be built (see {@see Request::DEFERRED_PROBLEM}). It sits right after
 * the origin check, so callers that fail the check get their 403 whatever else is wrong with the request.
 */
final readonly class DeferredProblemMiddleware implements Middleware
{
    public function process(Request $request, Handler $next): Response
    {
        $problem = $request->attribute(Request::DEFERRED_PROBLEM);
        if ($problem instanceof HttpProblem) {
            throw $problem;
        }

        return $next->handle($request);
    }
}
