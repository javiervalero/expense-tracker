<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Tests\Contract\BundledSpec;
use App\Tests\Support\Contract\ContractValidator;
use App\Tests\Support\Contract\ContractViolation;
use App\Tests\Support\Contract\OpenApiDocument;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class of the tests that call the API over HTTP: the real `api` (nginx) in front of `api-php` (php-fpm) on the
 * Compose network. {@see request()} sends the call to `/api/v1` + the path and checks the response against the
 * bundled contract (contract/dist/openapi.json); any violation fails the test. There is no opt-out: a response
 * the contract does not describe is a bug in the API or in the contract. A missing bundle fails too (never skips).
 *
 * {@see rawRequest()} is only for the nginx-level paths that are not part of the contract (outside `/api`, the 413
 * nginx answers by itself, the `Server` header).
 */
abstract class ApiTestCase extends TestCase
{
    private const string DEFAULT_BASE = 'http://api:8080';
    private const string API_PREFIX = '/api/v1';
    private const int QUEUED_CALL_TIMEOUT_SECONDS = 30;

    private static ?OpenApiDocument $document = null;

    private static ?ContractValidator $validator = null;

    private HttpClient $http;

    private string $base;

    /** The `X-Origin-Verify` value sent with every call; null sends none. Reset for each test. */
    private ?string $originHeader = null;

    /** The browser `Origin` sent on every call except GET and HEAD; null sends none. Reset for each test. */
    private ?string $browserOrigin = null;

    /** Whether `X-Requested-With: XMLHttpRequest` is sent on every call except GET and HEAD. Reset for each test. */
    private bool $sendRequestedWith = true;

    /** The `CloudFront-Viewer-Address` sent on every call, random for each test; null sends none. */
    private ?string $viewerAddress = null;

    /** The value of the session cookie sent on every call; null sends no cookie. Reset for each test. */
    private ?string $sessionCookie = null;

    final protected function setUp(): void
    {
        $this->browserOrigin = self::defaultBrowserOrigin();
        $this->sendRequestedWith = true;
        $this->viewerAddress = self::randomViewerAddress();
        $this->sessionCookie = null;
        $fromEnv = getenv('API_BASE_URL');
        $this->base = is_string($fromEnv) && $fromEnv !== '' ? rtrim($fromEnv, '/') : self::DEFAULT_BASE;
        $this->originHeader = self::localOriginSecret();
        $this->http = new HttpClient([
            'http_errors' => false,
            'allow_redirects' => false,
            'connect_timeout' => 3,
            'timeout' => 10,
        ]);
    }

    /**
     * From now on in this test, sends no `X-Origin-Verify` header (for the 403 cases).
     */
    final protected function withoutOriginHeader(): void
    {
        $this->originHeader = null;
    }

    /**
     * From now on in this test, sends `Origin: $origin` on the calls that need one (null: no `Origin` at all).
     */
    final protected function withBrowserOrigin(?string $origin): void
    {
        $this->browserOrigin = $origin;
    }

    /**
     * From now on in this test, sends no `X-Requested-With` header.
     */
    final protected function withoutRequestedWith(): void
    {
        $this->sendRequestedWith = false;
    }

    /**
     * From now on in this test, sends no `CloudFront-Viewer-Address`, so nginx fills it in from the socket (the
     * default is a random IPv6 address per test, which keeps the throttle buckets of one test from counting another's calls).
     */
    final protected function withoutViewerAddress(): void
    {
        $this->viewerAddress = null;
    }

    /**
     * From now on in this test, sends this client address as the `CloudFront-Viewer-Address` (in CloudFront's form:
     * `[ip]:port` for IPv6, `ip:port` for IPv4), instead of the random one of the test. For tests that need several
     * addresses, such as the per-account and per-network throttle limits.
     */
    final protected function withViewerAddress(string $ip): void
    {
        $this->viewerAddress = str_contains($ip, ':') ? sprintf('[%s]:%d', $ip, random_int(1024, 65535)) : sprintf('%s:%d', $ip, random_int(1024, 65535));
    }

    /**
     * From now on in this test, sends `Cookie: __Host-session=$value` on every call (null: no cookie). The value is
     * sent as given, so a test can send a malformed one.
     */
    final protected function withSessionCookie(?string $value): void
    {
        $this->sessionCookie = $value;
    }

    /**
     * The value of the `__Host-session` cookie that the response sets, or null when it sets none. Fails the test if
     * the response sets it more than once.
     */
    final protected function sessionCookieFrom(ResponseInterface $response): ?string
    {
        $values = [];
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (preg_match('/\A__Host-session=([^;]*)/', $cookie, $match) === 1) {
                $values[] = $match[1];
            }
        }
        self::assertLessThanOrEqual(1, count($values), 'The response sets the session cookie more than once.');

        return $values[0] ?? null;
    }

    /**
     * From now on in this test, sends `X-Origin-Verify: $value` instead of the local value of `ORIGIN_VERIFY_LOCAL`.
     */
    final protected function withOriginHeader(string $value): void
    {
        $this->originHeader = $value;
    }

    /**
     * Sends all calls under `/api/v1` at once through a Guzzle Pool (up to `$concurrency` in flight) and validates each
     * response against the contract, like request(). The calls get the defaults of the test; a header a call sets
     * itself wins, so each can carry its own `CloudFront-Viewer-Address`. A transport failure fails the test. The result
     * has the same keys as `$calls`.
     *
     * @param array<int, array{0: string, 1: string, 2?: array{body?: string, headers?: array<string, string|non-empty-list<string>>}}> $calls [method, path, options]
     *
     * @return array<int, ResponseInterface>
     */
    final protected function requestConcurrently(array $calls, int $concurrency): array
    {
        foreach ($calls as [, $path]) {
            self::assertStringStartsWith('/', $path, 'requestConcurrently() takes a path relative to the server URL of the spec');
            self::assertFalse(str_starts_with($path, '/api/'), "requestConcurrently() adds the /api/v1 prefix itself; got $path");
        }
        $requests = function () use ($calls): \Generator {
            foreach ($calls as $key => $call) {
                [$method, $path] = $call;
                $options = $this->withDefaults($method, $call[2] ?? []);
                // The Pool hands its own `options` to the callable; per-call options win over them (the later array of array_merge).
                /** @param array{timeout?: int|float} $poolOptions */
                // @phpstan-ignore argument.type (phpstan cannot see that the merge of two request-option shapes is one)
                yield $key => fn(array $poolOptions): PromiseInterface => $this->http->requestAsync($method, $this->base . self::API_PREFIX . $path, array_merge($poolOptions, $options));
            }
        };
        $responses = [];
        $failures = [];
        (new Pool($this->http, $requests(), [
            'concurrency' => $concurrency,
            // Calls queue behind the php-fpm workers, and the time spent waiting counts against the timeout.
            'options' => ['timeout' => self::QUEUED_CALL_TIMEOUT_SECONDS],
            'fulfilled' => static function (ResponseInterface $response, int|string $key) use (&$responses): void {
                $responses[$key] = $response;
            },
            'rejected' => static function (mixed $reason, int|string $key) use (&$failures): void {
                $failures[$key] = $reason instanceof \Throwable ? $reason->getMessage() : 'rejected';
            },
        ]))->promise()->wait();
        self::assertSame([], $failures, 'some concurrent calls failed at transport level');
        ksort($responses);
        foreach ($responses as $key => $response) {
            [$method, $path] = $calls[$key];
            self::assertMatchesContract($response, $method, $path, "$method $path (call $key)");
        }

        return $responses;
    }

    /**
     * Calls `/api/v1{$path}` and fails the test when the response differs from the contract.
     *
     * @param string                                                      $path    relative to the server URL of the spec, as in its `paths` (`/health`)
     * @param array{body?: string, headers?: array<string, string|non-empty-list<string>>} $options Guzzle request options
     */
    final protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        self::assertStringStartsWith('/', $path, 'request() takes a path relative to the server URL of the spec');
        self::assertFalse(str_starts_with($path, '/api/'), "request() adds the /api/v1 prefix itself; got $path");

        return $this->sendAs($method, $path, $method, self::API_PREFIX . $path, $options);
    }

    /**
     * Calls an absolute path under `/api/v1` and validates the response as the documented operation
     * ($operationMethod, $template): for the 404 and 405 answers, which the contract documents on an operation
     * although the request itself matches none (`/api/v1/unknown` as GET /health, POST /health as GET /health).
     *
     * @param string                                                      $absolutePathUnderApi `/api/v1/unknown`
     * @param array{body?: string, headers?: array<string, string|non-empty-list<string>>} $options                      Guzzle request options
     */
    final protected function requestAs(
        string $operationMethod,
        string $template,
        string $method,
        string $absolutePathUnderApi,
        array $options = [],
    ): ResponseInterface {
        self::assertStringStartsWith(self::API_PREFIX . '/', $absolutePathUnderApi);

        return $this->sendAs($operationMethod, $template, $method, $absolutePathUnderApi, $options);
    }

    /**
     * Calls any path on the nginx front, with no contract check. Only for what is not in the spec: it fails for a
     * path under `/api/v1` that is a documented operation for the method (use request()).
     *
     * @param string                                                      $path    absolute, from the site root (`/index.php`)
     * @param array{body?: string, headers?: array<string, string|non-empty-list<string>>} $options Guzzle request options
     */
    final protected function rawRequest(string $method, string $path, array $options = []): ResponseInterface
    {
        if (str_starts_with($path, self::API_PREFIX . '/')) {
            $documented = self::document()->pathTemplate(strtoupper($method), substr($path, strlen(self::API_PREFIX)));
            self::assertNull($documented, "$method $path is a documented operation ($documented): use request(), not rawRequest()");
        }

        return $this->http->request($method, $this->base . $path, $this->withDefaults($method, $options));
    }

    /**
     * @param array{body?: string, headers?: array<string, string|non-empty-list<string>>} $options
     */
    private function sendAs(string $operationMethod, string $template, string $method, string $url, array $options): ResponseInterface
    {
        $response = $this->http->request($method, $this->base . $url, $this->withDefaults($method, $options));
        self::assertMatchesContract($response, $operationMethod, $template, "$method $url");

        return $response;
    }

    /**
     * Fails the test when the response differs from the contract. The spec documents paths without the server prefix
     * and without a query string.
     */
    private static function assertMatchesContract(ResponseInterface $response, string $operationMethod, string $template, string $what): void
    {
        $violations = self::validator()->validate(
            $operationMethod,
            $template,
            $response->getStatusCode(),
            self::flatHeaders($response),
            (string) $response->getBody(),
        );
        self::assertSame([], array_map(self::describe(...), $violations), "$what breaks the contract (as $operationMethod $template)");
    }

    /**
     * The options plus the headers sent by default: `X-Origin-Verify` always, the random `CloudFront-Viewer-Address`
     * (unless the test removed it), the session cookie when the test set one, and on every method except GET and HEAD
     * the browser's `Origin` and `X-Requested-With` (what the CSRF check wants). A header the test sets itself in the
     * options wins, whatever its letter case.
     *
     * @param array{body?: string, headers?: array<string, string|non-empty-list<string>>} $options
     *
     * @return array{body?: string, headers?: array<string, string|non-empty-list<string>>}
     */
    private function withDefaults(string $method, array $options): array
    {
        $defaults = [];
        if ($this->originHeader !== null) {
            $defaults['X-Origin-Verify'] = $this->originHeader;
        }
        if ($this->viewerAddress !== null) {
            $defaults['CloudFront-Viewer-Address'] = $this->viewerAddress;
        }
        if ($this->sessionCookie !== null) {
            $defaults['Cookie'] = '__Host-session=' . $this->sessionCookie;
        }
        // OPTIONS gets the browser headers too, on purpose: harmless, the middleware exempts it.
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            if ($this->browserOrigin !== null) {
                $defaults['Origin'] = $this->browserOrigin;
            }
            if ($this->sendRequestedWith) {
                $defaults['X-Requested-With'] = 'XMLHttpRequest';
            }
        }
        $own = array_change_key_case($options['headers'] ?? [], CASE_LOWER);
        foreach ($defaults as $name => $value) {
            if (!array_key_exists(strtolower($name), $own)) {
                $options['headers'][$name] = $value;
            }
        }

        return $options;
    }

    /**
     * A random address in the documentation range `2001:db8::/32`, in CloudFront's form (`[ip]:port`). The /48 and
     * the /64 both differ from test to test, so no throttle bucket is shared.
     */
    private static function randomViewerAddress(): string
    {
        return sprintf('[2001:db8:%x:%x::1]:%d', random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(1024, 65535));
    }

    /**
     * The first entry of `APP_ORIGINS` (the same list the API checks), or the Vite dev server when unset.
     */
    private static function defaultBrowserOrigin(): string
    {
        $fromEnv = getenv('APP_ORIGINS');
        $first = is_string($fromEnv) ? trim(explode(',', $fromEnv)[0]) : '';

        return $first !== '' ? $first : 'http://localhost:5173';
    }

    /**
     * The local, non-secret origin value that the Compose stack shares with the API (`.env`, same default as Compose).
     */
    private static function localOriginSecret(): string
    {
        $fromEnv = getenv('ORIGIN_VERIFY_LOCAL');

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : 'local-origin-verify-not-a-secret';
    }

    /**
     * @return array<mixed>
     */
    final protected function problemBody(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function validator(): ContractValidator
    {
        return self::$validator ??= new ContractValidator(self::document());
    }

    private static function document(): OpenApiDocument
    {
        if (self::$document === null) {
            if (!is_file(BundledSpec::FILE)) {
                self::fail('contract/dist/openapi.json is missing; run `make contract-bundle` (API tests never run without the contract).');
            }
            self::$document = OpenApiDocument::fromFile(BundledSpec::FILE);
        }

        return self::$document;
    }

    /**
     * @return array<string, string>
     */
    private static function flatHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return $headers;
    }

    private static function describe(ContractViolation $violation): string
    {
        return $violation->location . ': ' . $violation->message;
    }
}
