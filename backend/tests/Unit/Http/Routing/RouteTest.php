<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Routing;

use App\Http\Handler;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\Route;
use App\Http\Routing\RouteDispatcher;
use App\Http\Routing\Router;
use LogicException;
use PHPUnit\Framework\TestCase;

/** A route can hold its handler or a factory for it; the factory runs only when the route is served. */
final class RouteTest extends TestCase
{
    private static function handler(int $status): Handler
    {
        return new class ($status) implements Handler {
            public function __construct(private readonly int $status) {}

            public function handle(Request $request): Response
            {
                return Response::empty($this->status);
            }
        };
    }

    public function testAHandlerGivenDirectlyIsReturnedAsIs(): void
    {
        $handler = self::handler(204);

        self::assertSame($handler, (new Route('GET', '/a', $handler))->handler());
    }

    public function testAFactoryIsNotCalledByTheConstructor(): void
    {
        $calls = 0;

        new Route('GET', '/a', static function () use (&$calls): Handler {
            ++$calls;

            return self::handler(204);
        });

        self::assertSame(0, $calls);
    }

    public function testTheFactoryRunsWhenTheHandlerIsAskedFor(): void
    {
        $route = new Route('GET', '/a', static fn(): Handler => self::handler(202));

        self::assertSame(202, $route->handler()->handle(new Request('GET', '/api/v1/a', [], [], ''))->status);
    }

    public function testAFactoryThatReturnsNoHandlerIsAProgrammingError(): void
    {
        $route = new Route('GET', '/a', static fn(): string => 'not a handler');

        $this->expectException(LogicException::class);

        $route->handler();
    }

    public function testADispatchedRequestBuildsOnlyTheHandlerOfTheRouteItMatched(): void
    {
        $built = [];
        $factory = static function (string $name, int $status) use (&$built): \Closure {
            return static function () use (&$built, $name, $status): Handler {
                $built[] = $name;

                return self::handler($status);
            };
        };
        $dispatcher = new RouteDispatcher(new Router([
            new Route('GET', '/a', $factory('a', 201)),
            new Route('GET', '/b', $factory('b', 202)),
        ]));

        $response = $dispatcher->handle(new Request('GET', '/api/v1/b', [], [], ''));
        $notFound = $dispatcher->handle(new Request('GET', '/api/v1/c', [], [], ''));
        $notAllowed = $dispatcher->handle(new Request('POST', '/api/v1/a', [], [], ''));

        self::assertSame(202, $response->status);
        self::assertSame(404, $notFound->status);
        self::assertSame(405, $notAllowed->status);
        self::assertSame(['b'], $built);
    }
}
