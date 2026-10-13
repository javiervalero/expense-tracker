<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Http\Problem\ProblemDetails;
use App\Http\Response;
use LogicException;

/**
 * Outcome of {@see Router::match()}: a matched route with its decoded parameters, no such path (404), or a
 * known path that does not accept the method (405, with the methods it does accept).
 */
final readonly class RouteResult
{
    /**
     * @param array<string, string> $params
     * @param list<string> $allow
     */
    private function __construct(
        private RouteOutcome $outcome,
        public ?Route $route,
        public array $params,
        public array $allow,
    ) {}

    /**
     * @param array<string, string> $params
     */
    public static function matched(Route $route, array $params): self
    {
        return new self(RouteOutcome::Matched, $route, $params, []);
    }

    public static function notFound(): self
    {
        return new self(RouteOutcome::NotFound, null, [], []);
    }

    /**
     * @param list<string> $allow
     */
    public static function methodNotAllowed(array $allow): self
    {
        return new self(RouteOutcome::MethodNotAllowed, null, [], $allow);
    }

    /** @phpstan-assert-if-true !null $this->route */
    public function isMatch(): bool
    {
        return $this->outcome === RouteOutcome::Matched;
    }

    public function isNotFound(): bool
    {
        return $this->outcome === RouteOutcome::NotFound;
    }

    public function isMethodNotAllowed(): bool
    {
        return $this->outcome === RouteOutcome::MethodNotAllowed;
    }

    /** The 404 or 405 problem-details response (405 carries `Allow`). */
    public function problemResponse(): Response
    {
        return match ($this->outcome) {
            RouteOutcome::NotFound => Response::problem(ProblemDetails::forStatus(404)),
            RouteOutcome::MethodNotAllowed => Response::problem(
                ProblemDetails::forStatus(405),
                ['Allow' => [implode(', ', $this->allow)]],
            ),
            default => throw new LogicException('A matched route has no problem response.'),
        };
    }
}
