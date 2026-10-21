<?php

declare(strict_types=1);

namespace App\Auth\Http;

use App\Http\Problem\HttpProblem;

/**
 * The `429` of the login and register throttles: one text and one `Retry-After`, so the two endpoints answer alike.
 */
final class TooManyAttempts
{
    private const DETAIL = 'Too many attempts. Try again later.';

    public static function problem(int $retryAfterSeconds): HttpProblem
    {
        return HttpProblem::of(429, self::DETAIL, headers: ['Retry-After' => [(string) $retryAfterSeconds]]);
    }
}
