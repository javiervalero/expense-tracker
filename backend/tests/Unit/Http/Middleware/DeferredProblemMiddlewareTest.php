<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware\DeferredProblemMiddleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class DeferredProblemMiddlewareTest extends TestCase
{
    private function next(int &$reached): Handler
    {
        return new class ($reached) implements Handler {
            public function __construct(private int &$reached) {}

            public function handle(Request $request): Response
            {
                ++$this->reached;

                return Response::empty(200);
            }
        };
    }

    public function testItRethrowsTheDeferredProblemWithoutCallingTheNextHandler(): void
    {
        $reached = 0;
        $problem = HttpProblem::of(413, 'too big');
        $request = (new Request('POST', '/x', [], [], ''))->withAttribute(Request::DEFERRED_PROBLEM, $problem);

        try {
            (new DeferredProblemMiddleware())->process($request, $this->next($reached));
            self::fail('Expected the deferred problem.');
        } catch (HttpProblem $thrown) {
            self::assertSame($problem, $thrown);
        }
        self::assertSame(0, $reached);
    }

    public function testItPassesARequestWithoutADeferredProblemOn(): void
    {
        $reached = 0;

        $response = (new DeferredProblemMiddleware())->process(new Request('GET', '/x', [], [], ''), $this->next($reached));

        self::assertSame(200, $response->status);
        self::assertSame(1, $reached);
    }

    public function testItIgnoresAnAttributeThatIsNotAnHttpProblem(): void
    {
        $reached = 0;
        $request = (new Request('GET', '/x', [], [], ''))->withAttribute(Request::DEFERRED_PROBLEM, 'nope');

        $response = (new DeferredProblemMiddleware())->process($request, $this->next($reached));

        self::assertSame(200, $response->status);
        self::assertSame(1, $reached);
    }
}
