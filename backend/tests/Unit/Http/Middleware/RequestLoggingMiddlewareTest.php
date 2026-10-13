<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware\RequestLoggingMiddleware;
use App\Http\Request;
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

    public function testThePathIsTruncatedTo256Characters(): void
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

    public function testTheDefaultClockIsMonotonicAndNonNegative(): void
    {
        $middleware = new RequestLoggingMiddleware(new JsonLogger($this->stream));

        $middleware->process(new Request('GET', '/api/v1/x', [], [], ''), $this->handler(static fn(): Response => Response::empty(200)));

        self::assertGreaterThanOrEqual(0, $this->line()['durationMs']);
    }
}
