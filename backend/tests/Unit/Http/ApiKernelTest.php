<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\ApiKernel;
use App\Http\Handler;
use App\Http\JsonBody;
use App\Http\Pipeline;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiKernelTest extends TestCase
{
    private function kernel(\Closure $fn, ?\Closure $ids = null): ApiKernel
    {
        $handler = new class ($fn) implements Handler {
            /** @param \Closure(Request): Response $fn */
            public function __construct(private \Closure $fn) {}

            public function handle(Request $request): Response
            {
                return ($this->fn)($request);
            }
        };

        return new ApiKernel($handler, $ids);
    }

    public function testEveryResponseGetsTheDefaultHeaders(): void
    {
        foreach ([200, 404, 405, 500] as $status) {
            $response = $this->kernel(static fn(): Response => Response::empty($status))
                ->handle(new Request('GET', '/api/v1/x', [], [], ''));

            self::assertSame($status, $response->status);
            self::assertSame(['no-store'], $response->headerValues('Cache-Control'), (string) $status);
            self::assertSame(['nosniff'], $response->headerValues('X-Content-Type-Options'), (string) $status);
        }
    }

    public function testTheDefaultHeadersReplaceWhatTheHandlerSet(): void
    {
        $response = $this->kernel(static fn(): Response => Response::empty(200, ['cache-control' => ['public']]))
            ->handle(new Request('GET', '/api/v1/x', [], [], ''));

        self::assertSame(['no-store'], $response->headerValues('Cache-Control'));
    }

    public function testTheHandlerSeesAGeneratedSixteenHexCharRequestId(): void
    {
        $seen = null;
        $this->kernel(static function (Request $r) use (&$seen): Response {
            $seen = $r->attribute(Request::REQUEST_ID);

            return Response::empty(200);
        })->handle(new Request('GET', '/api/v1/x', [], [], ''));

        self::assertIsString($seen);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $seen);
    }

    public function testAnInjectedIdGeneratorIsUsed(): void
    {
        $seen = null;
        $this->kernel(static function (Request $r) use (&$seen): Response {
            $seen = $r->attribute(Request::REQUEST_ID);

            return Response::empty(200);
        }, static fn(): string => 'fixed')->handle(new Request('GET', '/api/v1/x', [], [], ''));

        self::assertSame('fixed', $seen);
    }

    public function testItNeverThrowsAndAnswersAGeneric500(): void
    {
        $response = $this->kernel(static function (): Response {
            throw new RuntimeException('leak-me');
        })->handle(new Request('GET', '/api/v1/x', [], [], ''));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('leak-me', $response->body);
        self::assertSame(['no-store'], $response->headerValues('Cache-Control'));
    }

    public function testAThrowingIdGeneratorFallsBackToAFixedIdInHandle(): void
    {
        $seen = null;
        $response = $this->kernel(static function (Request $r) use (&$seen): Response {
            $seen = $r->attribute(Request::REQUEST_ID);

            return Response::empty(200);
        }, static fn(): string => throw new RuntimeException('no entropy'))
            ->handle(new Request('GET', '/api/v1/x', [], [], ''));

        self::assertSame(200, $response->status);
        self::assertSame('unknown', $seen);
    }

    public function testAThrowingIdGeneratorStillAnswersInHandleGlobals(): void
    {
        $kernel = $this->kernel(
            static fn(): Response => Response::empty(200),
            static fn(): string => throw new RuntimeException('no entropy'),
        );

        $ok = $kernel->handleGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/x'], '');
        $tooBig = $kernel->handleGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/x'],
            str_repeat('a', JsonBody::MAX_BYTES + 1),
        );

        self::assertSame(200, $ok->status);
        self::assertSame(200, $tooBig->status);
    }

    public function testHandleGlobalsBuildsTheRequestFromServerAndBody(): void
    {
        $seen = null;
        $kernel = $this->kernel(static function (Request $r) use (&$seen): Response {
            $seen = $r;

            return Response::empty(200);
        });

        $kernel->handleGlobals(['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/api/v1/x?a=1', 'QUERY_STRING' => 'a=1'], 'payload');

        self::assertInstanceOf(Request::class, $seen);
        self::assertSame('PUT', $seen->method);
        self::assertSame('/api/v1/x', $seen->path);
        self::assertSame('payload', $seen->body);
    }

    public function testAMalformedQueryReachesThePipelineAsABareRequestCarryingItsProblem(): void
    {
        $seen = null;
        $kernel = $this->kernel(static function (Request $r) use (&$seen): Response {
            $seen = $r;

            return Response::empty(204);
        });

        $response = $kernel->handleGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/x?a[=1', 'QUERY_STRING' => 'a[=1', 'HTTP_X_ORIGIN_VERIFY' => 'v'],
            'payload',
        );

        self::assertInstanceOf(Request::class, $seen);
        $problem = $seen->attribute(Request::DEFERRED_PROBLEM);
        self::assertInstanceOf(HttpProblem::class, $problem);
        self::assertSame(400, $problem->problem->status);
        self::assertSame('GET', $seen->method);
        self::assertSame('/api/v1/x', $seen->path);
        self::assertSame('v', $seen->header('x-origin-verify'));
        self::assertSame([], $seen->query);
        self::assertSame('', $seen->body);
        self::assertSame(204, $response->status);
        self::assertSame(['nosniff'], $response->headerValues('X-Content-Type-Options'));
    }

    public function testABodyOverTheJsonLimitReachesThePipelineCarryingA413(): void
    {
        $seen = null;
        $kernel = $this->kernel(static function (Request $r) use (&$seen): Response {
            $seen = $r;

            return Response::empty(204);
        });

        $kernel->handleGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/x', 'HTTP_X_ORIGIN_VERIFY' => 'v'],
            str_repeat('a', JsonBody::MAX_BYTES + 1),
        );

        self::assertInstanceOf(Request::class, $seen);
        $problem = $seen->attribute(Request::DEFERRED_PROBLEM);
        self::assertInstanceOf(HttpProblem::class, $problem);
        self::assertSame(413, $problem->problem->status);
        self::assertSame('', $seen->body);
        self::assertSame('v', $seen->header('x-origin-verify'));
    }

    public function testAnAcceptableRequestCarriesNoDeferredProblem(): void
    {
        $seen = null;
        $kernel = $this->kernel(static function (Request $r) use (&$seen): Response {
            $seen = $r;

            return Response::empty(200);
        });

        $kernel->handleGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/x'], '');

        self::assertInstanceOf(Request::class, $seen);
        self::assertNull($seen->attribute(Request::DEFERRED_PROBLEM));
    }

    /**
     * @param resource $stream
     *
     * @return list<array<string, mixed>>
     */
    private function lines(mixed $stream): array
    {
        rewind($stream);
        $lines = [];
        foreach (explode("\n", trim((string) stream_get_contents($stream))) as $raw) {
            if ($raw !== '') {
                $lines[] = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        /** @var list<array<string, mixed>> $lines */

        return $lines;
    }

    /**
     * @return resource
     */
    private function memory(): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);

        return $stream;
    }

    public function testABodyOfExactlyTheLimitPassesOn(): void
    {
        $kernel = $this->kernel(static fn(): Response => Response::empty(200));

        $response = $kernel->handleGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/x'],
            str_repeat('a', JsonBody::MAX_BYTES),
        );

        self::assertSame(200, $response->status);
    }

    public function testALastResortFailureRedactsTheSecretsOfItsLogger(): void
    {
        $secret = 'Slot-A-0123456789-0123456789-abcdef';
        $stream = $this->memory();
        $kernel = new ApiKernel($this->kernelHandler(static function () use ($secret): Response {
            throw new RuntimeException(str_repeat('m', 1000) . $secret);
        }), null, new JsonLogger($stream, null, [$secret]));

        $kernel->handle(new Request('GET', '/api/v1/x', [], [], ''));

        rewind($stream);
        $logged = (string) stream_get_contents($stream);
        self::assertStringContainsString('request failed past the error handler', $logged);
        self::assertStringNotContainsString(substr($secret, 0, 8), $logged);
    }

    public function testALastResortFailureIsLoggedWithoutTheTrace(): void
    {
        $stream = $this->memory();
        $kernel = new ApiKernel($this->kernelHandler(static function (): Response {
            throw new RuntimeException(str_repeat('m', 2000));
        }), static fn(): string => 'rid-boom', new JsonLogger($stream));

        $response = $kernel->handle(new Request('GET', '/api/v1/x', [], [], ''));

        self::assertSame(500, $response->status);
        $line = $this->lines($stream)[0];
        self::assertSame('error', $line['level']);
        self::assertSame('rid-boom', $line['requestId']);
        self::assertSame(RuntimeException::class, $line['exception']);
        self::assertIsString($line['exceptionMessage']);
        self::assertSame(1024, strlen($line['exceptionMessage']));
        self::assertSame(__FILE__, $line['file']);
        self::assertIsInt($line['line']);
        self::assertArrayNotHasKey('trace', $line);
    }

    public function testALastResortFailureWithAThrowingLoggerStillAnswers500(): void
    {
        $logger = new JsonLogger($this->memory(), static fn() => throw new RuntimeException('clock'));
        $kernel = new ApiKernel($this->kernelHandler(static function (): Response {
            throw new RuntimeException('boom');
        }), null, $logger);

        $response = $kernel->handle(new Request('GET', '/api/v1/x', [], [], ''));

        self::assertSame(500, $response->status);
        self::assertSame(['nosniff'], $response->headerValues('X-Content-Type-Options'));
    }

    /**
     * @param \Closure(Request): Response $fn
     */
    private function kernelHandler(\Closure $fn): Handler
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

    public function testItWorksOverARealPipeline(): void
    {
        $kernel = new ApiKernel(new Pipeline([], $this->endpoint()));

        self::assertSame(200, $kernel->handle(new Request('GET', '/api/v1/x', [], [], ''))->status);
    }

    private function endpoint(): Handler
    {
        return new class implements Handler {
            public function handle(Request $request): Response
            {
                return Response::empty(200);
            }
        };
    }
}
