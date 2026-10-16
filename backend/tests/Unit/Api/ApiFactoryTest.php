<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\ApiFactory;
use App\Api\Routes;
use App\Tests\Support\Api\OfflineServices;
use App\Http\Handler;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\Route;
use App\Infrastructure\Config\ApiConfig;
use App\Infrastructure\Config\InvalidConfiguration;
use App\Infrastructure\DynamoDb\StorageFailure;
use PHPUnit\Framework\TestCase;

final class ApiFactoryTest extends TestCase
{
    private const SECRET_A = 'factory-slot-a-0123456789-0123456789';
    private const SECRET_B = 'factory-slot-b-0123456789-0123456789';
    private const ENV = ['ORIGIN_VERIFY_SECRETS' => self::SECRET_A . ',' . self::SECRET_B, 'CLIENT_IP_HEADER' => 'CloudFront-Viewer-Address', 'DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1'];

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
        return ['x-origin-verify' => $secret, 'cloudfront-viewer-address' => '198.51.100.20:5000'];
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

    public function testTheOriginCheckComesBeforeTheDeferredProblemOfAMalformedRequest(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health?a[=1', 'QUERY_STRING' => 'a[=1'];
        $big = str_repeat('a', 70000);

        $noHeaderQuery = ApiFactory::fromEnvironment(self::ENV, $this->stream)->handleGlobals($server, '');
        $noHeaderBody = ApiFactory::fromEnvironment(self::ENV, $this->stream)->handleGlobals(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x'], $big);
        $withHeaderQuery = ApiFactory::fromEnvironment(self::ENV, $this->stream)->handleGlobals($server + ['HTTP_X_ORIGIN_VERIFY' => self::SECRET_B], '');
        $withHeaderBody = ApiFactory::fromEnvironment(self::ENV, $this->stream)->handleGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x', 'HTTP_X_ORIGIN_VERIFY' => self::SECRET_A],
            $big,
        );

        self::assertSame([403, 403, 400, 413], [$noHeaderQuery->status, $noHeaderBody->status, $withHeaderQuery->status, $withHeaderBody->status]);
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
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => $other, 'CLIENT_IP_HEADER' => 'CloudFront-Viewer-Address', 'DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
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

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        $lines = [];
        foreach (explode("\n", trim($this->logged())) as $raw) {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            $lines[] = $decoded;
        }

        return $lines;
    }

    public function testTheRequestLineCarriesTheClientIpFromTheConfiguredHeader(): void
    {
        $kernel = ApiFactory::fromEnvironment(self::ENV, $this->stream);

        $kernel->handle(new Request('GET', '/api/v1/health', [], ['cloudfront-viewer-address' => '203.0.113.7:4711'] + self::origin(), ''));

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('203.0.113.7', $lines[0]['clientIp']);
    }

    public function testAnUnusableClientAddressIsServedLoggedAsAnErrorAndShownAsUnknown(): void
    {
        $kernel = ApiFactory::fromEnvironment(self::ENV, $this->stream);

        $response = $kernel->handle(new Request('GET', '/api/v1/health', [], ['cloudfront-viewer-address' => '1.1.1.1:1, 2.2.2.2:2'] + self::origin(), ''));

        self::assertSame(200, $response->status);
        $lines = $this->lines();
        self::assertSame('client address unavailable', $lines[0]['message']);
        self::assertSame('multiple-values', $lines[0]['reason']);
        self::assertSame('unknown', $lines[1]['clientIp']);
    }

    public function testARequestRejectedByTheOriginCheckNeverReachesClientIp(): void
    {
        $kernel = ApiFactory::fromEnvironment(self::ENV, $this->stream);

        $kernel->handle(new Request('GET', '/api/v1/health', [], ['cloudfront-viewer-address' => '203.0.113.7:4711'], ''));

        $lines = $this->lines();
        self::assertSame(['origin verification failed', 'request'], array_column($lines, 'message'));
        self::assertSame(403, $lines[1]['status']);
        self::assertSame('unknown', $lines[1]['clientIp']);
    }

    public function testAnInvalidDynamoDbSettingGives500OnHealthAndTheLogNamesTheVariableOnly(): void
    {
        $env = ['DYNAMODB_TABLE' => 'x', 'AWS_REGION' => 'mars-hunter2'] + self::ENV;
        $kernel = ApiFactory::fromEnvironment($env, $this->stream);

        $response = $kernel->handle(new Request('GET', '/api/v1/health', [], self::origin(), ''));

        self::assertSame(500, $response->status);
        self::assertStringContainsString('DYNAMODB_TABLE', $this->logged());
        self::assertStringContainsString('AWS_REGION', $this->logged());
        self::assertStringNotContainsString('hunter2', $this->logged());
        self::assertStringNotContainsString('DYNAMODB', $response->body);
    }

    public function testALocalEnvironmentWithoutAnEndpointGives500(): void
    {
        $kernel = ApiFactory::fromEnvironment(['APP_ENV' => 'local'] + self::ENV, $this->stream);

        $response = $kernel->handle(new Request('GET', '/api/v1/health', [], self::origin(), ''));

        self::assertSame(500, $response->status);
        self::assertStringContainsString('DYNAMODB_ENDPOINT', $this->logged());
    }

    public function testAStorageOverloadBelowTheChainBecomesA503WithRetryAfterAndIsLoggedOnce(): void
    {
        $overloaded = new Route('GET', '/overloaded', new class implements Handler {
            public function handle(Request $request): Response
            {
                throw new StorageFailure('GetItem', 'ThrottlingException', 'REQ-9', 400);
            }
        });
        $config = ApiConfig::fromEnvironment(self::ENV);
        $kernel = ApiFactory::fromConfigLoader(static fn(): ApiConfig => $config, $this->stream, [$overloaded]);

        $response = $kernel->handle(new Request('GET', '/api/v1/overloaded', [], self::origin(), ''));

        self::assertSame(503, $response->status);
        self::assertSame(['1'], $response->headerValues('Retry-After'));
        self::assertSame(1, substr_count($this->logged(), '"message":"storage unavailable"'));
        self::assertStringContainsString('REQ-9', $this->logged());
    }

    public function testAnotherStorageFailureStaysAGeneric500WithoutRetryAfter(): void
    {
        $denied = new Route('GET', '/denied', new class implements Handler {
            public function handle(Request $request): Response
            {
                throw new StorageFailure('GetItem', 'AccessDeniedException');
            }
        });
        $config = ApiConfig::fromEnvironment(self::ENV);
        $kernel = ApiFactory::fromConfigLoader(static fn(): ApiConfig => $config, $this->stream, [$denied]);

        $response = $kernel->handle(new Request('GET', '/api/v1/denied', [], self::origin(), ''));

        self::assertSame(500, $response->status);
        self::assertSame([], $response->headerValues('Retry-After'));
        self::assertStringNotContainsString('storage unavailable', $this->logged());
    }

    public function testTheApiServicesBuildOfflineAndTheRouteTableTakesThem(): void
    {
        $services = OfflineServices::create();

        $routes = Routes::all($services);

        self::assertSame(['GET /health', 'HEAD /health'], array_map(static fn(Route $route): string => $route->method . ' ' . $route->template, $routes));
    }
}
