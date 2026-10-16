<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Routing;

use App\Http\Handler;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\Route;
use App\Http\Routing\RouteDispatcher;
use App\Http\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouteDispatcherTest extends TestCase
{
    private function dispatcher(): RouteDispatcher
    {
        $echo = new class implements Handler {
            public function handle(Request $request): Response
            {
                return Response::json(200, $request->attribute(Request::ROUTE_PARAMS));
            }
        };

        return new RouteDispatcher(new Router([new Route('GET', '/things/{id}', $echo)]));
    }

    public function testAMatchCallsTheRouteHandlerWithTheParamsAsAnAttribute(): void
    {
        $response = $this->dispatcher()->handle(new Request('GET', '/api/v1/things/a%20b', [], [], ''));

        self::assertSame(200, $response->status);
        self::assertSame('{"id":"a b"}', $response->body);
    }

    public function testNoRouteIsA404Problem(): void
    {
        $response = $this->dispatcher()->handle(new Request('GET', '/api/v1/nope', [], [], ''));

        self::assertSame(404, $response->status);
    }

    public function testAWrongMethodIsA405WithAllow(): void
    {
        $response = $this->dispatcher()->handle(new Request('DELETE', '/api/v1/things/1', [], [], ''));

        self::assertSame(405, $response->status);
        self::assertSame(['GET'], $response->headerValues('Allow'));
    }

    public function testARequestCarryingADeferredProblemIsRefusedAndNoHandlerRuns(): void
    {
        $ran = false;
        $handler = new class ($ran) implements Handler {
            public function __construct(public bool &$ran) {}

            public function handle(Request $request): Response
            {
                $this->ran = true;

                return Response::json(200, []);
            }
        };
        $dispatcher = new RouteDispatcher(new Router([new Route('GET', '/things/{id}', $handler)]));
        $problem = HttpProblem::of(400, 'Bad query.');
        $request = (new Request('GET', '/api/v1/things/1', [], [], ''))->withAttributes([Request::DEFERRED_PROBLEM => $problem]);

        try {
            $dispatcher->handle($request);
            self::fail('Expected the deferred problem to be thrown.');
        } catch (HttpProblem $thrown) {
            self::assertSame($problem, $thrown);
        }
        self::assertFalse($ran);
    }
}
