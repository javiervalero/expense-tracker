<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware\OriginVerifyMiddleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginVerifyMiddlewareTest extends TestCase
{
    private const SLOT_A = 'Slot-A-0123456789-0123456789-abcdef';
    private const SLOT_B = 'Slot-B-0123456789-0123456789-abcdef';

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

    private function middleware(string ...$slots): OriginVerifyMiddleware
    {
        return new OriginVerifyMiddleware(new JsonLogger($this->stream), $slots === [] ? [self::SLOT_A, self::SLOT_B] : array_values($slots));
    }

    private function next(): Handler
    {
        return new class ($this) implements Handler {
            public function __construct(private OriginVerifyMiddlewareTest $test) {}

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
    private function request(array $headers = [], string $method = 'GET', string $path = '/api/v1/health'): Request
    {
        return new Request($method, $path, [], $headers, '');
    }

    private function assertForbidden(Request $request, ?OriginVerifyMiddleware $middleware = null): HttpProblem
    {
        try {
            ($middleware ?? $this->middleware())->process($request, $this->next());
        } catch (HttpProblem $problem) {
            self::assertSame(403, $problem->problem->status);
            self::assertSame(0, $this->reached, 'The next handler must not run.');

            return $problem;
        }
        self::fail('Expected a 403 HttpProblem.');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function rejectedHeaders(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['x-origin-verify' => '']];
        yield 'wrong' => [['x-origin-verify' => 'not-the-secret-not-the-secret-not-the-secret']];
        yield 'a prefix of the secret' => [['x-origin-verify' => substr(self::SLOT_A, 0, -1)]];
        yield 'the secret plus a suffix' => [['x-origin-verify' => self::SLOT_A . 'x']];
        yield 'a case change' => [['x-origin-verify' => strtolower(self::SLOT_A)]];
        yield 'comma-merged duplicate' => [['x-origin-verify' => self::SLOT_A . ', ' . self::SLOT_A]];
        yield 'comma-merged without space' => [['x-origin-verify' => self::SLOT_A . ',' . self::SLOT_B]];
        yield 'leading space' => [['x-origin-verify' => ' ' . self::SLOT_A]];
        yield 'trailing space' => [['x-origin-verify' => self::SLOT_A . ' ']];
        yield 'trailing newline' => [['x-origin-verify' => self::SLOT_A . "\n"]];
        yield 'whitespace only' => [['x-origin-verify' => '   ']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('rejectedHeaders')]
    public function testARejectedHeaderGetsAGeneric403AndNeverReachesTheHandler(array $headers): void
    {
        $problem = $this->assertForbidden($this->request($headers));

        self::assertSame(['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403], $problem->problem->toArray());
        self::assertSame([], $problem->headers);
    }

    public function testTheBodyIsTheSameWhateverTheReason(): void
    {
        $missing = $this->assertForbidden($this->request());
        $wrong = $this->assertForbidden($this->request(['x-origin-verify' => 'x' . self::SLOT_A]));

        self::assertEquals($missing->problem, $wrong->problem);
        self::assertSame($missing->getMessage(), $wrong->getMessage());
    }

    public function testSlotAPasses(): void
    {
        $response = $this->middleware()->process($this->request(['x-origin-verify' => self::SLOT_A]), $this->next());

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->reached);
    }

    public function testSlotBPasses(): void
    {
        $response = $this->middleware()->process($this->request(['x-origin-verify' => self::SLOT_B]), $this->next());

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->reached);
    }

    public function testASingleConfiguredSlotWorksAndTheOtherValueIsRejected(): void
    {
        $only = $this->middleware(self::SLOT_A);

        self::assertSame(200, $only->process($this->request(['x-origin-verify' => self::SLOT_A]), $this->next())->status);
        $this->reached = 0;
        $this->assertForbidden($this->request(['x-origin-verify' => self::SLOT_B]), $only);
    }

    public function testNoSlotsConfiguredRejectsEverything(): void
    {
        $this->assertForbidden($this->request(['x-origin-verify' => '']), new OriginVerifyMiddleware(new JsonLogger($this->stream), []));
        $this->assertForbidden($this->request(['x-origin-verify' => self::SLOT_A]), new OriginVerifyMiddleware(new JsonLogger($this->stream), []));
    }

    public function testEveryMethodAndPathIsGuarded(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach (['/api/v1/health', '/api/v1/unknown', '/'] as $path) {
                $this->assertForbidden($this->request([], $method, $path));
            }
        }
    }

    public function testHeadAndOptionsPassWithTheRightHeader(): void
    {
        foreach (['HEAD', 'OPTIONS'] as $method) {
            $response = $this->middleware()->process(
                $this->request(['x-origin-verify' => self::SLOT_B], $method),
                $this->next(),
            );

            self::assertSame(200, $response->status, $method);
        }
    }

    public function testTheFailureIsLoggedAsAWarningWithMethodPathAndReasonMissing(): void
    {
        foreach ([[], ['x-origin-verify' => '']] as $headers) {
            $this->setUp();
            $this->assertForbidden($this->request($headers, 'POST', '/api/v1/expenses'));

            $entry = json_decode($this->logged(), true);
            self::assertIsArray($entry);
            self::assertSame('warning', $entry['level']);
            self::assertSame('origin verification failed', $entry['message']);
            self::assertSame('POST', $entry['method']);
            self::assertSame('/api/v1/expenses', $entry['path']);
            self::assertSame('missing', $entry['reason']);
        }
    }

    public function testAWrongValueIsLoggedAsMismatch(): void
    {
        $this->assertForbidden($this->request(['x-origin-verify' => 'wrong-value']));

        $entry = json_decode($this->logged(), true);
        self::assertIsArray($entry);
        self::assertSame('mismatch', $entry['reason']);
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('rejectedHeaders')]
    public function testTheHeaderValueNeverReachesTheLog(array $headers): void
    {
        $this->assertForbidden($this->request($headers));

        $log = $this->logged();
        self::assertNotSame('', $log);
        foreach ([self::SLOT_A, self::SLOT_B, strtolower(self::SLOT_A), 'not-the-secret'] as $value) {
            self::assertStringNotContainsString($value, $log);
        }
    }

    public function testAFailureLogsNoOtherRequestHeaderEither(): void
    {
        $this->assertForbidden($this->request(['x-origin-verify' => 'leak-me-leak-me', 'cookie' => 'sid=abc', 'authorization' => 'Bearer t']));

        self::assertStringNotContainsString('leak-me', $this->logged());
        self::assertStringNotContainsString('sid=abc', $this->logged());
        self::assertStringNotContainsString('Bearer', $this->logged());
    }

    public function testAHugePathAndAnUnknownMethodAreCappedInTheLog(): void
    {
        $this->assertForbidden($this->request([], 'BREW' . str_repeat('x', 5000), '/' . str_repeat('p', 5000)));

        $entry = json_decode($this->logged(), true);
        self::assertIsArray($entry);
        self::assertSame('OTHER', $entry['method']);
        self::assertIsString($entry['path']);
        self::assertSame(256, strlen($entry['path']));
    }

    public function testAnAcceptedRequestLogsNothing(): void
    {
        $this->middleware()->process($this->request(['x-origin-verify' => self::SLOT_A]), $this->next());

        self::assertSame('', $this->logged());
    }
}
