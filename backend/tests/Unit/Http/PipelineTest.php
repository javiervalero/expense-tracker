<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Handler;
use App\Http\Middleware;
use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function testWithoutMiddlewareItCallsTheFinalHandler(): void
    {
        $pipeline = new Pipeline([], $this->handler(static fn(Request $r): Response => Response::empty(204)));

        self::assertSame(204, $pipeline->handle($this->request())->status);
    }

    public function testMiddlewareRunOutermostFirstAndUnwindInReverse(): void
    {
        $trace = new \ArrayObject();
        $pipeline = new Pipeline(
            [$this->tracing('a', $trace), $this->tracing('b', $trace)],
            $this->handler(static function (Request $r) use ($trace): Response {
                $trace->append('handler');

                return Response::empty(200);
            }),
        );

        $pipeline->handle($this->request());

        self::assertSame(['a in', 'b in', 'handler', 'b out', 'a out'], iterator_to_array($trace));
    }

    public function testAMiddlewareCanShortCircuit(): void
    {
        $reached = false;
        $blocker = new class implements Middleware {
            public function process(Request $request, Handler $next): Response
            {
                return Response::empty(403);
            }
        };
        $pipeline = new Pipeline([$blocker], $this->handler(static function (Request $r) use (&$reached): Response {
            $reached = true;

            return Response::empty(200);
        }));

        self::assertSame(403, $pipeline->handle($this->request())->status);
        self::assertFalse($reached);
    }

    public function testAMiddlewareCanPassAModifiedRequestAndModifyTheResponse(): void
    {
        $tagger = new class implements Middleware {
            public function process(Request $request, Handler $next): Response
            {
                return $next->handle($request->withAttribute('tag', 'seen'))->withHeader('X-Done', '1');
            }
        };
        $pipeline = new Pipeline([$tagger], $this->handler(
            static fn(Request $r): Response => Response::json(200, ['tag' => $r->attribute('tag')]),
        ));

        $response = $pipeline->handle($this->request());

        self::assertSame('{"tag":"seen"}', $response->body);
        self::assertSame(['1'], $response->headerValues('X-Done'));
    }

    public function testAPipelineCanBeRunMoreThanOnce(): void
    {
        $calls = 0;
        $pipeline = new Pipeline([], $this->handler(static function (Request $r) use (&$calls): Response {
            ++$calls;

            return Response::empty(200);
        }));

        $pipeline->handle($this->request());
        $pipeline->handle($this->request());

        self::assertSame(2, $calls);
    }

    public function testAnExceptionPropagatesToTheOuterMiddleware(): void
    {
        $catcher = new class implements Middleware {
            public function process(Request $request, Handler $next): Response
            {
                try {
                    return $next->handle($request);
                } catch (\RuntimeException) {
                    return Response::empty(500);
                }
            }
        };
        $pipeline = new Pipeline([$catcher], $this->handler(
            static fn(Request $r): Response => throw new \RuntimeException('boom'),
        ));

        self::assertSame(500, $pipeline->handle($this->request())->status);
    }

    /**
     * @param \ArrayObject<int, string> $trace
     */
    private function tracing(string $name, \ArrayObject $trace): Middleware
    {
        return new class ($name, $trace) implements Middleware {
            /**
             * @param \ArrayObject<int, string> $trace
             */
            public function __construct(private readonly string $name, private readonly \ArrayObject $trace) {}

            public function process(Request $request, Handler $next): Response
            {
                $this->trace->append($this->name . ' in');
                $response = $next->handle($request);
                $this->trace->append($this->name . ' out');

                return $response;
            }
        };
    }

    /**
     * @param \Closure(Request): Response $callback
     */
    private function handler(\Closure $callback): Handler
    {
        return new class ($callback) implements Handler {
            /**
             * @param \Closure(Request): Response $callback
             */
            public function __construct(private readonly \Closure $callback) {}

            public function handle(Request $request): Response
            {
                return ($this->callback)($request);
            }
        };
    }

    private function request(): Request
    {
        return new Request('GET', '/', [], [], '');
    }
}
