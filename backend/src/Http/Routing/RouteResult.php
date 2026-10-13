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
    private const MATCHED = 'matched';
    private const NOT_FOUND = 'not-found';
    private const METHOD_NOT_ALLOWED = 'method-not-allowed';

    /**
     * @param array<string, string> $params
     * @param list<string> $allow
     */
    private function __construct(
        private string $outcome,
        public ?Route $route,
        public array $params,
        public array $allow,
    ) {}

    /**
     * @param array<string, string> $params
     */
    public static function matched(Route $route, array $params): self
    {
        return new self(self::MATCHED, $route, $params, []);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null, [], []);
    }

    /**
     * @param list<string> $allow
     */
    public static function methodNotAllowed(array $allow): self
    {
        return new self(self::METHOD_NOT_ALLOWED, null, [], $allow);
    }

    public function isMatch(): bool
    {
        return $this->outcome === self::MATCHED;
    }

    public function isNotFound(): bool
    {
        return $this->outcome === self::NOT_FOUND;
    }

    public function isMethodNotAllowed(): bool
    {
        return $this->outcome === self::METHOD_NOT_ALLOWED;
    }

    /** The 404 or 405 problem-details response (405 carries `Allow`). */
    public function problemResponse(): Response
    {
        return match ($this->outcome) {
            self::NOT_FOUND => Response::problem(ProblemDetails::forStatus(404)),
            self::METHOD_NOT_ALLOWED => Response::problem(
                ProblemDetails::forStatus(405),
                ['Allow' => [implode(', ', $this->allow)]],
            ),
            default => throw new LogicException('A matched route has no problem response.'),
        };
    }
}
