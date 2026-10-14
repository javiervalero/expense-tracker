<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\ApiFactory;
use App\Http\Request;
use App\Infrastructure\Config\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class ApiFactoryTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    public function testTheKernelServesHealthForGetAndHead(): void
    {
        $kernel = ApiFactory::fromEnvironment([], $this->stream);

        foreach (['GET', 'HEAD'] as $method) {
            $response = $kernel->handle(new Request($method, '/api/v1/health', [], [], ''));

            self::assertSame(200, $response->status, $method);
            self::assertSame(['no-store'], $response->headerValues('Cache-Control'));
        }
    }

    public function testNotFoundAndMethodNotAllowedKeepTheirProblemsAndDefaultHeaders(): void
    {
        $kernel = ApiFactory::fromEnvironment([], $this->stream);

        $notFound = $kernel->handle(new Request('GET', '/api/v1/nope', [], [], ''));
        $notAllowed = $kernel->handle(new Request('POST', '/api/v1/health', [], [], ''));

        self::assertSame(404, $notFound->status);
        self::assertSame(405, $notAllowed->status);
        self::assertSame(['GET, HEAD'], $notAllowed->headerValues('Allow'));
        self::assertSame(['nosniff'], $notAllowed->headerValues('X-Content-Type-Options'));
    }

    public function testEachRequestIsLoggedOnce(): void
    {
        ApiFactory::fromEnvironment([], $this->stream)->handle(new Request('GET', '/api/v1/health', [], [], ''));

        self::assertSame(1, substr_count($this->logged(), "\n"));
        self::assertStringContainsString('"requestId":"', $this->logged());
    }

    public function testAClientErrorIsLoggedExactlyOnceWithItsRealStatus(): void
    {
        $response = ApiFactory::fromEnvironment([], $this->stream)->handle(new Request('POST', '/api/v1/health', [], [], ''));

        self::assertSame(405, $response->status);
        self::assertSame(1, substr_count($this->logged(), "\n"));
        self::assertStringContainsString('"status":405', $this->logged());
        self::assertStringNotContainsString('"level":"error"', $this->logged());
    }

    public function testInvalidConfigurationYieldsAFailClosedKernelThatAnswers500ToEverything(): void
    {
        $kernel = ApiFactory::fromConfigLoader(
            static fn() => throw new InvalidConfiguration(['SOME_VARIABLE is missing']),
            $this->stream,
        );

        foreach (['/api/v1/health', '/api/v1/anything', '/'] as $path) {
            $response = $kernel->handle(new Request('GET', $path, [], [], ''));

            self::assertSame(500, $response->status, $path);
            self::assertSame(['no-store'], $response->headerValues('Cache-Control'));
            self::assertStringNotContainsString('SOME_VARIABLE', $response->body);
        }
        self::assertStringContainsString('SOME_VARIABLE is missing', $this->logged());
    }

    public function testAnyOtherStartupFailureIsFailClosedAndOnlyItsClassIsLogged(): void
    {
        foreach ([new \ValueError('hunter2-secret'), new \TypeError('hunter2-secret')] as $error) {
            $this->setUp();
            $kernel = ApiFactory::fromConfigLoader(static fn() => throw $error, $this->stream);

            $response = $kernel->handle(new Request('GET', '/api/v1/health', [], [], ''));

            self::assertSame(500, $response->status, $error::class);
            self::assertStringNotContainsString('hunter2-secret', $this->logged());
            self::assertStringNotContainsString('hunter2-secret', $response->body);
            self::assertStringContainsString($error::class, $this->logged());
        }
    }
}
