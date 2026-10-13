<?php

declare(strict_types=1);

namespace App\Http\Problem;

use RuntimeException;
use Throwable;

/**
 * Thrown anywhere in request handling to end it with a problem-details response. The exception message is
 * the problem's detail (or its title), so it is safe to show to the client: never put input or internals in it.
 */
class HttpProblem extends RuntimeException
{
    /**
     * @param array<string, list<string>> $headers extra response headers (Retry-After, ...) for the error middleware
     *                                             to pass to {@see \App\Http\Response::problem()}
     */
    public function __construct(
        public readonly ProblemDetails $problem,
        ?Throwable $previous = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($problem->detail ?? $problem->title, $problem->status, $previous);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public static function of(int $status, ?string $detail = null, ?Throwable $previous = null, array $headers = []): self
    {
        return new self(ProblemDetails::forStatus($status, $detail), $previous, $headers);
    }
}
