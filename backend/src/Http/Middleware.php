<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Wraps the rest of the pipeline: it may answer itself, or call `$next` with a (modified) request and
 * change the response that comes back.
 */
interface Middleware
{
    public function process(Request $request, Handler $next): Response;
}
