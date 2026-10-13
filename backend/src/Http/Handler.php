<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Turns a request into a response. Implementations throw {@see Problem\HttpProblem} to end with a problem.
 */
interface Handler
{
    public function handle(Request $request): Response;
}
