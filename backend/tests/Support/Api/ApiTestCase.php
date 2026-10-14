<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Tests\Support\Contract\ContractValidator;
use App\Tests\Support\Contract\ContractViolation;
use App\Tests\Support\Contract\OpenApiDocument;
use GuzzleHttp\Client as HttpClient;
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
    private const string BUNDLE = __DIR__ . '/../../../../contract/dist/openapi.json';

    private static ?ContractValidator $validator = null;

    private HttpClient $http;

    private string $base;

    protected function setUp(): void
    {
        $fromEnv = getenv('API_BASE_URL');
        $this->base = is_string($fromEnv) && $fromEnv !== '' ? rtrim($fromEnv, '/') : self::DEFAULT_BASE;
        $this->http = new HttpClient(['http_errors' => false, 'timeout' => 10]);
    }

    /**
     * Calls `/api/v1{$path}` and fails the test when the response differs from the contract.
     *
     * @param string                                                      $path    relative to the server URL of the spec, as in its `paths` (`/health`)
     * @param array{body?: string, headers?: array<string, string>} $options Guzzle request options
     */
    final protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $response = $this->rawRequest($method, self::API_PREFIX . $path, $options);
        // The spec documents paths without the server prefix and without a query string.
        $violations = self::validator()->validate(
            $method,
            $path,
            $response->getStatusCode(),
            self::flatHeaders($response),
            (string) $response->getBody(),
        );
        self::assertSame([], array_map(self::describe(...), $violations), "$method $path breaks the contract");

        return $response;
    }

    /**
     * Calls any path on the nginx front, with no contract check. Only for what is not in the spec.
     *
     * @param string                                                      $path    absolute, from the site root (`/index.php`)
     * @param array{body?: string, headers?: array<string, string>} $options Guzzle request options
     */
    final protected function rawRequest(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->http->request($method, $this->base . $path, $options);
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
        if (self::$validator === null) {
            if (!is_file(self::BUNDLE)) {
                self::fail('contract/dist/openapi.json is missing; run `make contract-bundle` (API tests never run without the contract).');
            }
            self::$validator = new ContractValidator(OpenApiDocument::fromFile(self::BUNDLE));
        }

        return self::$validator;
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
