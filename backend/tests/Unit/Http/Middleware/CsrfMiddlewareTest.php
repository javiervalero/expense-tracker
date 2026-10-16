<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    private const ALLOWED = 'http://localhost:5173';
    private const OTHER_ALLOWED = 'https://app.example.com';
    private const BROWSER = ['x-requested-with' => 'XMLHttpRequest', 'origin' => self::ALLOWED];

    /** @var resource */
    private $stream;

    private int $reached = 0;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
        $this->reached = 0;
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(): array
    {
        $entry = json_decode($this->logged(), true);
        self::assertIsArray($entry);

        return $entry;
    }

    private function middleware(): CsrfMiddleware
    {
        return new CsrfMiddleware(new JsonLogger($this->stream), [self::ALLOWED, self::OTHER_ALLOWED]);
    }

    private function next(): Handler
    {
        return new class ($this) implements Handler {
            public function __construct(private CsrfMiddlewareTest $test) {}

            public function handle(Request $request): Response
            {
                $this->test->markReached();

                return Response::json(200, ['status' => 'ok']);
            }
        };
    }

    public function markReached(): void
    {
        ++$this->reached;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, array $headers = [], string $path = '/api/v1/expenses'): Request
    {
        return new Request($method, $path, [], $headers, '');
    }

    /**
     * @param array<string, string> $headers
     */
    private function assertForbidden(string $method, array $headers): void
    {
        try {
            $this->middleware()->process($this->request($method, $headers), $this->next());
        } catch (HttpProblem $problem) {
            self::assertSame(403, $problem->problem->status);
            self::assertSame('The request failed the cross-site request check.', $problem->problem->detail);
            self::assertSame(0, $this->reached, 'The next handler must not run.');

            return;
        }
        self::fail('Expected a 403 HttpProblem.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
        yield 'lower-case get' => ['get'];
    }

    #[DataProvider('safeMethods')]
    public function testSafeMethodsPassWithNoHeaders(string $method): void
    {
        $response = $this->middleware()->process($this->request($method), $this->next());

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->reached);
        self::assertSame('', $this->logged());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
        yield 'PROPFIND' => ['PROPFIND'];
        yield 'lower-case post' => ['post'];
        yield 'mixed-case Delete' => ['Delete'];
    }

    #[DataProvider('unsafeMethods')]
    public function testUnsafeMethodsWithNothingAre403(string $method): void
    {
        $this->assertForbidden($method, []);
    }

    #[DataProvider('unsafeMethods')]
    public function testUnsafeMethodsWithBothRightPass(string $method): void
    {
        $response = $this->middleware()->process($this->request($method, self::BROWSER), $this->next());

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->reached);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function rejected(): iterable
    {
        $origin = ['origin' => self::ALLOWED];
        $header = ['x-requested-with' => 'XMLHttpRequest'];
        yield 'header in lower case' => [['x-requested-with' => 'xmlhttprequest'] + $origin, 'bad-header'];
        yield 'header empty' => [['x-requested-with' => ''] + $origin, 'bad-header'];
        yield 'header comma-joined' => [['x-requested-with' => 'XMLHttpRequest, XMLHttpRequest'] + $origin, 'bad-header'];
        yield 'header padded' => [['x-requested-with' => ' XMLHttpRequest'] + $origin, 'bad-header'];
        yield 'header missing' => [$origin, 'missing-header'];
        yield 'origin missing' => [$header, 'missing-origin'];
        yield 'origin empty' => [$header + ['origin' => ''], 'missing-origin'];
        yield 'origin null' => [$header + ['origin' => 'null'], 'foreign-origin'];
        yield 'origin with a trailing slash' => [$header + ['origin' => 'http://localhost:5173/'], 'foreign-origin'];
        yield 'origin with another port' => [$header + ['origin' => 'http://localhost:5174'], 'foreign-origin'];
        yield 'origin with another scheme' => [$header + ['origin' => 'https://localhost:5173'], 'foreign-origin'];
        yield 'origin in upper case' => [$header + ['origin' => 'http://LOCALHOST:5173'], 'foreign-origin'];
        yield 'origin comma-joined with a foreign one' => [$header + ['origin' => 'http://localhost:5173, http://evil.example'], 'foreign-origin'];
        yield 'origin comma-joined with two allowed' => [$header + ['origin' => self::ALLOWED . ',' . self::OTHER_ALLOWED], 'foreign-origin'];
        yield 'origin with a leading space' => [$header + ['origin' => ' http://localhost:5173'], 'foreign-origin'];
        yield 'origin with a trailing space' => [$header + ['origin' => 'http://localhost:5173 '], 'foreign-origin'];
        yield 'origin with a trailing newline' => [$header + ['origin' => "http://localhost:5173\n"], 'foreign-origin'];
        yield 'foreign origin' => [$header + ['origin' => 'https://evil.example'], 'foreign-origin'];
        yield 'a prefix of an allowed origin' => [$header + ['origin' => 'http://localhost'], 'foreign-origin'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('rejected')]
    public function testAMissingOrWrongHeaderOrOriginIs403WithTheReasonLogged(array $headers, string $reason): void
    {
        $this->assertForbidden('POST', $headers);

        $entry = $this->entry();
        self::assertSame('warning', $entry['level']);
        self::assertSame('csrf check failed', $entry['message']);
        self::assertSame('POST', $entry['method']);
        self::assertSame('/api/v1/expenses', $entry['path']);
        self::assertSame($reason, $entry['reason']);
    }

    public function testAnotherAllowedOriginPasses(): void
    {
        $headers = ['x-requested-with' => 'XMLHttpRequest', 'origin' => self::OTHER_ALLOWED];

        self::assertSame(200, $this->middleware()->process($this->request('PUT', $headers), $this->next())->status);
    }

    public function testTheHeaderIsCheckedBeforeTheOrigin(): void
    {
        $this->assertForbidden('POST', ['origin' => 'https://evil.example']);

        self::assertSame('missing-header', $this->entry()['reason']);
    }

    public function testAnOriginValueIsLoggedCappedAt128Bytes(): void
    {
        $this->assertForbidden('POST', ['x-requested-with' => 'XMLHttpRequest', 'origin' => 'https://' . str_repeat('é', 300)]);

        $origin = $this->entry()['origin'];
        self::assertIsString($origin);
        self::assertLessThanOrEqual(128, strlen($origin));
        self::assertGreaterThan(100, strlen($origin));
        self::assertTrue(mb_check_encoding($origin, 'UTF-8'));
    }

    public function testAMissingOriginIsLoggedAsEmpty(): void
    {
        $this->assertForbidden('POST', ['x-requested-with' => 'XMLHttpRequest']);

        self::assertSame('', $this->entry()['origin']);
    }

    public function testThePathAndAnUnknownMethodAreCappedInTheLog(): void
    {
        try {
            $this->middleware()->process($this->request('BREW' . str_repeat('x', 5000), [], '/' . str_repeat('p', 5000)), $this->next());
            self::fail('Expected a 403 HttpProblem.');
        } catch (HttpProblem) {
            $entry = $this->entry();
            self::assertSame('OTHER', $entry['method']);
            self::assertIsString($entry['path']);
            self::assertSame(256, strlen($entry['path']));
        }
    }

    public function testNoOtherHeaderReachesTheLog(): void
    {
        $this->assertForbidden('POST', ['cookie' => 'sid=abc', 'authorization' => 'Bearer t', 'x-origin-verify' => 'leak-me']);

        foreach (['sid=abc', 'Bearer', 'leak-me'] as $value) {
            self::assertStringNotContainsString($value, $this->logged());
        }
    }

    public function testNoConfiguredOriginRejectsEveryUnsafeRequest(): void
    {
        $this->expectException(HttpProblem::class);

        (new CsrfMiddleware(new JsonLogger($this->stream), []))->process($this->request('POST', self::BROWSER), $this->next());
    }
}
