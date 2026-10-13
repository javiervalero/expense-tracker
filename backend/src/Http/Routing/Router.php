<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Http\Request;
use InvalidArgumentException;

/**
 * Matches a request to a {@see Route}. Routes live under {@see self::PREFIX}; their templates are relative to it.
 *
 * Matching works on the RAW path (as sent, still percent-encoded), segment by segment, and decodes only afterwards:
 *
 *  - the path must be `/api/v1/` plus one or more non-empty segments, so a trailing slash, `//`, another version
 *    or a path outside the prefix is not found; matching is case-sensitive and exact;
 *  - literal segments are compared raw: `%68ealth` does not match `health`. Dot segments are not resolved;
 *  - `%2F` is never a separator: segments are split on the raw "/" only, so an encoded slash stays inside its
 *    segment. A `{name}` parameter matches any one non-empty segment;
 *  - parameter values are percent-decoded once, after matching ("+" stays "+");
 *  - a segment whose decoded text contains "/" or "\", is "." or "..", or contains a control/format
 *    (Unicode C category) character, or a line/paragraph separator (U+2028, U+2029) is 404 like the other
 *    unroutable paths. The C category covers controls such as NEL, format characters such as the U+202E bidi
 *    override, unassigned and private use code points, so a parameter can never smuggle a path or an
 *    unprintable character into a storage key. Anything else is allowed, including "#" (`a%23b`): handlers MUST validate
 *    ids (e.g. as a UUID) before building storage keys from them;
 *  - a path that is longer than {@see self::MAX_PATH_LENGTH}, holds a control character (including NUL and DEL),
 *    has an invalid percent escape, or has any segment that does not decode to valid UTF-8 without control
 *    characters (`%00`, `%0A`, `%FF`) is 404 (not 400) before any matching, for every method: such a path
 *    cannot name a resource, and the response does not reveal which part was wrong;
 *  - the query string is not part of the path (it lives in `Request::$query`) and never affects routing;
 *  - the method is compared exactly (case-sensitive). A known path with another method is 405 with the `Allow`
 *    list (registration order). HEAD is only served if a HEAD route is registered; OPTIONS is just another 405;
 *  - when several routes match the same path and method (`/expenses/export` and `/expenses/{id}`), the first
 *    registered wins: register literal routes before parameter routes.
 */
final readonly class Router
{
    public const PREFIX = '/api/v1';
    public const MAX_PATH_LENGTH = 2048;

    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';
    private const UNSAFE_CHARACTER = '/[\/\\\\]|[\p{C}\p{Zl}\p{Zp}]/u';
    private const DOT_SEGMENTS = ['.', '..'];
    private const INVALID_ESCAPE = '/%(?![0-9A-Fa-f]{2})/';

    /**
     * @param list<Route> $routes
     */
    public function __construct(private array $routes)
    {
        $shapes = [];
        foreach ($routes as $route) {
            if (isset($shapes[$route->shape()])) {
                throw new InvalidArgumentException('Duplicate route: ' . $route->method . ' ' . $route->template);
            }
            $shapes[$route->shape()] = true;
        }
        self::assertNoShadowing($routes);
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function match(Request $request): RouteResult
    {
        $segments = $this->segmentsOf($request->path);
        if ($segments === null) {
            return RouteResult::notFound();
        }

        $allow = [];
        $found = null;
        foreach ($this->routes as $route) {
            $rawParams = $this->matchSegments($route->segments(), $segments);
            if ($rawParams === null) {
                continue;
            }
            $allow[$route->method] = $route->method;
            if ($found === null && $route->method === $request->method) {
                $found = [$route, $rawParams];
            }
        }

        if ($found !== null) {
            return RouteResult::matched($found[0], $this->decodeAll($found[1]));
        }

        return $allow === [] ? RouteResult::notFound() : RouteResult::methodNotAllowed(array_values($allow));
    }

    /**
     * @return list<string>|null the raw segments after the prefix, or null if the path cannot be routed
     */
    private function segmentsOf(string $path): ?array
    {
        $prefix = self::PREFIX . '/';
        if (
            strlen($path) > self::MAX_PATH_LENGTH
            || !str_starts_with($path, $prefix)
            || preg_match(self::CONTROL_CHARACTERS, $path) !== 0
            || preg_match(self::INVALID_ESCAPE, $path) !== 0
        ) {
            return null;
        }

        $segments = explode('/', substr($path, strlen($prefix)));
        foreach ($segments as $segment) {
            if ($segment === '' || $this->decode($segment) === null) {
                return null;
            }
        }

        return $segments;
    }

    /**
     * @param list<string> $templateSegments
     * @param list<string> $pathSegments
     *
     * @return array<string, string>|null raw parameter values, or null if the route does not match the path
     */
    private function matchSegments(array $templateSegments, array $pathSegments): ?array
    {
        if (count($templateSegments) !== count($pathSegments)) {
            return null;
        }

        $params = [];
        foreach ($templateSegments as $index => $templateSegment) {
            $name = Route::parameterOf($templateSegment);
            if ($name !== null) {
                $params[$name] = $pathSegments[$index];
            } elseif ($templateSegment !== $pathSegments[$index]) {
                return null;
            }
        }

        return $params;
    }

    /**
     * @param array<string, string> $rawParams already checked by {@see self::segmentsOf()}
     *
     * @return array<string, string>
     */
    private function decodeAll(array $rawParams): array
    {
        return array_map(static fn(string $raw): string => rawurldecode($raw), $rawParams);
    }

    /** The segment percent-decoded once, or null if the result is not clean UTF-8 text. */
    private function decode(string $segment): ?string
    {
        $value = rawurldecode($segment);

        if (
            preg_match('//u', $value) !== 1
            || in_array($value, self::DOT_SEGMENTS, true)
            || preg_match(self::UNSAFE_CHARACTER, $value) !== 0
        ) {
            return null;
        }

        return $value;
    }

    /**
     * A route with a parameter that covers every path of a LATER route with the same method would make that route
     * unreachable (first registered wins), which is a registration mistake: register the literal one first.
     *
     * @param list<Route> $routes
     */
    private static function assertNoShadowing(array $routes): void
    {
        foreach ($routes as $index => $earlier) {
            foreach (array_slice($routes, $index + 1) as $later) {
                if ($earlier->method === $later->method && self::covers($earlier, $later)) {
                    throw new InvalidArgumentException(
                        'Route ' . $earlier->template . ' shadows ' . $later->template . '; register it after.',
                    );
                }
            }
        }
    }

    private static function covers(Route $earlier, Route $later): bool
    {
        $laterSegments = $later->segments();
        $earlierSegments = $earlier->segments();
        if (count($earlierSegments) !== count($laterSegments)) {
            return false;
        }
        foreach ($earlierSegments as $position => $segment) {
            if (Route::parameterOf($segment) === null && $segment !== $laterSegments[$position]) {
                return false;
            }
        }

        return true;
    }
}
