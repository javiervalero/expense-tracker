<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\ApiFactory;
use App\Http\Handler;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\Route;
use App\Infrastructure\Config\ApiConfig;
use App\Infrastructure\Config\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class ApiFactoryTest extends TestCase
{
    private const SECRET_A = 'factory-slot-a-0123456789-0123456789';
    private const SECRET_B = 'factory-slot-b-0123456789-0123456789';
    private const ENV = ['ORIGIN_VERIFY_SECRETS' => self::SECRET_A . ',' . self::SECRET_B];

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

    /**
     * @return array<string, string>
     */
    private static function origin(string $secret = self::SECRET_A): array
    {
        return ['x-origin-verify' => $secret];
    }

    public function testTheKernelServesHealthForGetAndHead(): void
    {
        $kernel = ApiFactory::fromEnvironment(self::ENV, $this->stream);

        foreach (['GET', 'HEAD'] as $method) {
            $response = $kernel->handle(new Request($method, '/api/v1/health', [], self::origin(), ''));

            self::assertSame(200, $response->status, $method);
            self::assertSame(['no-store'], $response->headerValues('Cache-Control'));
        }
    }

    public function testNotFoundAndMethodNotAllowedKeepTheirProblemsAndDefaultHeaders(): void
    {
        $kernel = ApiFactory::fromEnvironment(self::ENV, $this->stream);

        $notFound = $kernel->handle(new Request('GET', '/api/v1/nope', [], self::origin(), ''));
        $notAllowed = $kernel->handle(new Request('POST', '/api/v1/health', [], self::origin(), ''));

        self::assertSame(404, $notFound->status);
        self::assertSame(405, $notAllowed->status);
        self::assertSame(['GET, HEAD'], $notAllowed->headerValues('Allow'));
        self::assertSame(['nosniff'], $notAllowed->headerValues('X-Content-Type-Options'));
    }

    public function testEachRequestIsLoggedOnce(): void
    {
        ApiFactory::fromEnvironment(self::ENV, $this->stream)->handle(new Request('GET', '/api/v1/health', [], self::origin(), ''));

        self::assertSame(1, substr_count($this->logged(), "\n"));
        self::assertStringContainsString('"requestId":"', $this->logged());
    }

    public function testAClientErrorIsLoggedExactlyOnceWithItsRealStatus(): void
    {
        $response = ApiFactory::fromEnvironment(self::ENV, $this->stream)->handle(new Request('POST', '/api/v1/health', [], self::origin(), ''));

        self::assertSame(405, $response->status);
        self::assertSame(1, substr_count($this->logged(), "\n"));
        self::assertStringContainsString('"status":405', $this->logged());
        self::assertStringNotContainsString('"level":"error"', $this->logged());
    }

    public function testARequestWithoutTheOriginHeaderIs403ForEveryPathAndIsLoggedOnce(): void
    {
        foreach (['/api/v1/health', '/api/v1/unknown', '/'] as $path) {
            $this->setUp();
            $kernel = ApiFactory::fromEnvironment(self::ENV, $this->stream);
            $response = $kernel->handle(new Request('GET', $path, [], [], ''));

            self::assertSame(403, $response->status, $path);
            self::assertSame('application/problem+json', $response->headerValues('Content-Type')[0], $path);
            self::assertSame(['no-store'], $response->headerValues('Cache-Control'), $path);
            self::assertSame('{"type":"about:blank","title":"Forbidden","status":403}', $response->body, $path);
            self::assertSame(2, substr_count($this->logged(), "\n"), 'one warning and one request line');
            self::assertStringContainsString('"message":"origin verification failed"', $this->logged());
            self::assertStringContainsString('"message":"request"', $this->logged());
            self::assertStringContainsString('"status":403', $this->logged());
        }
    }

    public function testBothSlotsPassAndAWrongValueIs403(): void
    {
        $kernel = ApiFactory::fromEnvironment(self::ENV, $this->stream);

        foreach ([self::SECRET_A => 200, self::SECRET_B => 200, 'nope' => 403] as $secret => $status) {
            $response = $kernel->handle(new Request('GET', '/api/v1/health', [], self::origin($secret), ''));

            self::assertSame($status, $response->status, $secret);
        }
        self::assertStringNotContainsString(self::SECRET_A, $this->logged());
        self::assertStringNotContainsString(self::SECRET_B, $this->logged());
    }

    public function testASecretInAnExceptionMessageIsRedactedInTheRequestPathLog(): void
    {
        $config = ApiConfig::fromEnvironment(self::ENV);
        $leaky = new Route('GET', '/leak', new class (self::SECRET_B) implements Handler {
            public function __construct(private string $secret) {}

            public function handle(Request $request): Response
            {
                throw new \RuntimeException('boom with ' . $this->secret . ' inside');
            }
        });
        $kernel = ApiFactory::fromConfigLoader(static fn(): ApiConfig => $config, $this->stream, [$leaky]);

        $response = $kernel->handle(new Request('GET', '/api/v1/leak', [], self::origin(), ''));

        self::assertSame(500, $response->status);
        self::assertStringContainsString('boom with [REDACTED] inside', $this->logged());
        self::assertStringNotContainsString(self::SECRET_A, $this->logged());
        self::assertStringNotContainsString(self::SECRET_B, $this->logged());
    }

    public function testTheFactoryUsesTheLoadedConfigAndNeverReadsTheEnvironmentItself(): void
    {
        $other = 'loader-slot-0123456789-0123456789-xx';
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => $other]);
        $kernel = ApiFactory::fromConfigLoader(static fn(): ApiConfig => $config, $this->stream);

        self::assertSame(200, $kernel->handle(new Request('GET', '/api/v1/health', [], self::origin($other), ''))->status);
        self::assertSame(403, $kernel->handle(new Request('GET', '/api/v1/health', [], self::origin(), ''))->status);
    }

    public function testMissingOrEmptyOriginSecretsFailClosedWith500NamingOnlyTheVariable(): void
    {
        foreach ([[], ['ORIGIN_VERIFY_SECRETS' => ''], ['ORIGIN_VERIFY_SECRETS' => '   '], ['ORIGIN_VERIFY_SECRETS' => 'too-short-hunter2']] as $env) {
            $this->setUp();
            $kernel = ApiFactory::fromEnvironment($env, $this->stream);

            foreach ([self::origin(), []] as $headers) {
                $response = $kernel->handle(new Request('GET', '/api/v1/health', [], $headers, ''));

                self::assertSame(500, $response->status);
                self::assertStringNotContainsString('ORIGIN_VERIFY', $response->body);
            }
            self::assertStringContainsString('ORIGIN_VERIFY_SECRETS', $this->logged());
            self::assertStringNotContainsString('hunter2', $this->logged());
        }
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
