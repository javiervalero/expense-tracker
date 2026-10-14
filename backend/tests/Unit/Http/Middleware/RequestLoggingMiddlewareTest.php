<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware\RequestLoggingMiddleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestLoggingMiddlewareTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(): array
    {
        rewind($this->stream);
        $line = json_decode((string) stream_get_contents($this->stream), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($line);
        /** @var array<string, mixed> $line */

        return $line;
    }

    private function middleware(): RequestLoggingMiddleware
    {
        $ticks = [1_000_000_000, 1_042_500_000];

        return new RequestLoggingMiddleware(
            new JsonLogger($this->stream),
            static function () use (&$ticks): int {
                return array_shift($ticks) ?? 0;
            },
        );
    }

    private function handler(\Closure $fn): Handler
    {
        return new class ($fn) implements Handler {
            /** @param \Closure(Request): Response $fn */
            public function __construct(private \Closure $fn) {}

            public function handle(Request $request): Response
            {
                return ($this->fn)($request);
            }
        };
    }

    public function testItLogsOneLineWithTheRequestFacts(): void
    {
        $request = (new Request('POST', '/api/v1/things', ['q' => 'v'], [], ''))->withAttribute(Request::REQUEST_ID, 'rid1');

        $response = $this->middleware()->process($request, $this->handler(static fn(): Response => Response::empty(201)));

        self::assertSame(201, $response->status);
        $line = $this->line();
        self::assertSame('rid1', $line['requestId']);
        self::assertSame('POST', $line['method']);
        self::assertSame('/api/v1/things', $line['path']);
        self::assertSame(201, $line['status']);
        self::assertSame(42.5, $line['durationMs']);
        rewind($this->stream);
        self::assertSame(1, substr_count((string) stream_get_contents($this->stream), "\n"));
    }

    public function testItNeverLogsHeadersCookiesBodyOrQueryValues(): void
    {
        $request = new Request(
            'POST',
            '/api/v1/things',
            ['token' => 'query-secret'],
            ['cookie' => 'sid=cookie-secret', 'authorization' => 'Bearer header-secret'],
            'body-secret',
        );

        $this->middleware()->process($request, $this->handler(static fn(): Response => Response::empty(200)));

        rewind($this->stream);
        $raw = (string) stream_get_contents($this->stream);
        foreach (['query-secret', 'cookie-secret', 'header-secret', 'body-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
    }

    public function testThePathIsTruncatedTo256Bytes(): void
    {
        $request = new Request('GET', '/api/v1/' . str_repeat('a', 400), [], [], '');

        $this->middleware()->process($request, $this->handler(static fn(): Response => Response::empty(404)));

        $path = $this->line()['path'];
        self::assertIsString($path);
        self::assertSame(256, strlen($path));
    }

    public function testAThrowingHandlerIsLoggedAs500AndTheExceptionPropagates(): void
    {
        $request = new Request('GET', '/api/v1/boom', [], [], '');

        try {
            $this->middleware()->process($request, $this->handler(static function (): Response {
                throw new RuntimeException('boom');
            }));
            self::fail('Expected the exception to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(500, $this->line()['status']);
    }

    public function testAnHttpProblemIsLoggedWithItsOwnStatus(): void
    {
        foreach ([401, 413] as $status) {
            $this->setUp();
            try {
                $this->middleware()->process(
                    new Request('POST', '/api/v1/things', [], [], ''),
                    $this->handler(static function () use ($status): Response {
                        throw HttpProblem::of($status);
                    }),
                );
                self::fail('Expected the problem to propagate.');
            } catch (HttpProblem $problem) {
                self::assertSame($status, $problem->problem->status);
            }

            self::assertSame($status, $this->line()['status']);
        }
    }

    public function testAnUnknownMethodIsLoggedAsOther(): void
    {
        $this->middleware()->process(
            new Request('BREW' . str_repeat('x', 500), '/api/v1/x', [], [], ''),
            $this->handler(static fn(): Response => Response::empty(405)),
        );

        self::assertSame('OTHER', $this->line()['method']);
    }

    public function testEveryKnownMethodIsLoggedAsItself(): void
    {
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->setUp();
            $this->middleware()->process(
                new Request($method, '/api/v1/x', [], [], ''),
                $this->handler(static fn(): Response => Response::empty(200)),
            );

            self::assertSame($method, $this->line()['method']);
        }
    }

    public function testTheDefaultClockIsMonotonicAndNonNegative(): void
    {
        $middleware = new RequestLoggingMiddleware(new JsonLogger($this->stream));

        $middleware->process(new Request('GET', '/api/v1/x', [], [], ''), $this->handler(static fn(): Response => Response::empty(200)));

        self::assertGreaterThanOrEqual(0, $this->line()['durationMs']);
    }

    public function testClientIpIsUnknownWhenNothingBelowSetIt(): void
    {
        $this->middleware()->process($this->request(), $this->handler(static fn(): Response => Response::empty(204)));

        self::assertSame('unknown', $this->line()['clientIp']);
    }

    public function testClientIpIsTheOneThatAMiddlewareBelowRecorded(): void
    {
        $this->middleware()->process($this->request(), $this->handler(static function (Request $request): Response {
            $context = $request->attribute(RequestLogContext::ATTRIBUTE);
            self::assertInstanceOf(RequestLogContext::class, $context);
            $context->clientIp = '203.0.113.7';

            return Response::empty(204);
        }));

        self::assertSame('203.0.113.7', $this->line()['clientIp']);
    }

    public function testClientIpIsLoggedWhenTheRequestFails(): void
    {
        try {
            $this->middleware()->process($this->request(), $this->handler(static function (Request $request): Response {
                $context = $request->attribute(RequestLogContext::ATTRIBUTE);
                self::assertInstanceOf(RequestLogContext::class, $context);
                $context->clientIp = '198.51.100.4';

                throw new RuntimeException('boom');
            }));
            self::fail('Expected the exception to propagate.');
        } catch (RuntimeException) {
        }

        self::assertSame('198.51.100.4', $this->line()['clientIp']);
    }

    private function request(): Request
    {
        return new Request('GET', '/api/v1/health', [], [], '');
    }
}
