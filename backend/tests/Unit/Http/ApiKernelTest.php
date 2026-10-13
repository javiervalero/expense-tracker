<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\ApiKernel;
use App\Http\Handler;
use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Response;
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

    public function testHandleGlobalsTurnsAMalformedQueryIntoItsProblemWithDefaultHeaders(): void
    {
        $response = $this->kernel(static fn(): Response => Response::empty(200))
            ->handleGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/x?a=%zz', 'QUERY_STRING' => 'a=%zz'], '');

        self::assertSame(400, $response->status);
        self::assertSame(['nosniff'], $response->headerValues('X-Content-Type-Options'));
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
