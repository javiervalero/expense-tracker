<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Http\Handler;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * One operation: an upper-case HTTP method, a path template relative to {@see Router::PREFIX} in OpenAPI syntax
 * (`/expenses/{expenseId}`) and the handler that serves it. A template is one or more segments, each a literal
 * (letters, digits, `.`, `_`, `~`, `-`) or a whole-segment `{name}` parameter; names are unique within a template.
 *
 * The handler is given as a {@see Handler} or as a factory `Closure(): Handler`. A factory runs each time
 * {@see self::handler()} is called (the dispatcher calls it once, for the route it matched), so a request builds only
 * the handler, and the stores behind it, that it needs.
 */
final readonly class Route
{
    private const DOT_SEGMENTS = ['.', '..'];
    private const METHOD = '/^[A-Z]+$/D';
    private const TEMPLATE = '#^(?:/(?:[A-Za-z0-9._~-]+|\{[A-Za-z][A-Za-z0-9_]*\}))+$#D';

    public function __construct(
        public string $method,
        public string $template,
        private Handler|Closure $handler,
    ) {
        if (preg_match(self::METHOD, $method) !== 1) {
            throw new InvalidArgumentException('A route method is upper-case letters.');
        }
        if (preg_match(self::TEMPLATE, $template) !== 1 || str_starts_with($template, Router::PREFIX . '/')) {
            throw new InvalidArgumentException('Invalid route template "' . $template . '".');
        }
        if (array_intersect($this->segments(), self::DOT_SEGMENTS) !== []) {
            throw new InvalidArgumentException('A route template has a dot segment, which can never match: "' . $template . '".');
        }
        $names = $this->parameterNames();
        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('A route template repeats a parameter name: "' . $template . '".');
        }
    }

    /**
     * @throws LogicException when the factory does not return a Handler
     */
    public function handler(): Handler
    {
        if ($this->handler instanceof Handler) {
            return $this->handler;
        }
        $built = ($this->handler)();
        if (!$built instanceof Handler) {
            throw new LogicException('The handler factory of route ' . $this->method . ' ' . $this->template . ' did not return a Handler.');
        }

        return $built;
    }

    /**
     * @return list<string> the template split in segments
     */
    public function segments(): array
    {
        return explode('/', substr($this->template, 1));
    }

    /** The parameter name of a segment, or null for a literal segment. */
    public static function parameterOf(string $segment): ?string
    {
        return str_starts_with($segment, '{') ? substr($segment, 1, -1) : null;
    }

    /**
     * The template with every parameter name erased, to tell whether two routes cover the same paths.
     */
    public function shape(): string
    {
        return $this->method . ' ' . implode('/', array_map(
            static fn(string $segment): string => self::parameterOf($segment) === null ? $segment : '{}',
            $this->segments(),
        ));
    }

    /**
     * @return list<string>
     */
    private function parameterNames(): array
    {
        return array_values(array_filter(
            array_map(self::parameterOf(...), $this->segments()),
            static fn(?string $name): bool => $name !== null,
        ));
    }
}
