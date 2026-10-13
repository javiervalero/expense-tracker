<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware\ErrorHandlingMiddleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorHandlingMiddlewareTest extends TestCase
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

    /**
     * @param array<string, mixed> $attributes
     */
    private function process(\Closure $next, array $attributes = []): Response
    {
        $handler = new class ($next) implements Handler {
            /** @param \Closure(Request): Response $next */
            public function __construct(private \Closure $next) {}

            public function handle(Request $request): Response
            {
                return ($this->next)($request);
            }
        };
        $request = (new Request('GET', '/api/v1/x', [], [], ''))->withAttributes($attributes);

        return (new ErrorHandlingMiddleware(new JsonLogger($this->stream)))->process($request, $handler);
    }

    public function testAResponseFromTheNextHandlerPassesThrough(): void
    {
        $response = $this->process(static fn(): Response => Response::empty(204));

        self::assertSame(204, $response->status);
        self::assertSame('', $this->logged());
    }

    public function testAnHttpProblemBecomesItsResponseWithItsHeaders(): void
    {
        $response = $this->process(static function (): Response {
            throw HttpProblem::of(429, 'Slow down', null, ['Retry-After' => ['5']]);
        });

        self::assertSame(429, $response->status);
        self::assertSame(['5'], $response->headerValues('Retry-After'));
        self::assertSame(['application/problem+json'], $response->headerValues('Content-Type'));
        self::assertStringContainsString('Slow down', $response->body);
        self::assertSame('', $this->logged(), 'a client error is not an application error');
    }

    public function testAServerSideHttpProblemIsLogged(): void
    {
        $response = $this->process(static function (): Response {
            throw HttpProblem::of(503);
        });

        self::assertSame(503, $response->status);
        self::assertStringContainsString('"status":503', $this->logged());
    }

    public function testAnyOtherThrowableIsAGeneric500WithoutItsMessage(): void
    {
        $response = $this->process(
            static function (): Response {
                throw new RuntimeException('secret-ish', 0, new RuntimeException('inner-secret'));
            },
            [Request::REQUEST_ID => 'abc123'],
        );

        self::assertSame(500, $response->status);
        self::assertSame(['application/problem+json'], $response->headerValues('Content-Type'));
        self::assertStringNotContainsString('secret-ish', $response->body);
        self::assertStringNotContainsString('inner-secret', $response->body);
        self::assertStringNotContainsString('RuntimeException', $response->body);
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500],
            json_decode($response->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testTheLogLineHasRequestIdClassMessageFileAndLineButNoTraceOrPrevious(): void
    {
        $this->process(
            static function (): Response {
                throw new RuntimeException('secret-ish', 0, new RuntimeException('inner-secret'));
            },
            [Request::REQUEST_ID => 'abc123'],
        );

        $line = json_decode($this->logged(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($line);
        self::assertSame('error', $line['level']);
        self::assertSame('abc123', $line['requestId']);
        self::assertSame(RuntimeException::class, $line['exception']);
        self::assertSame('secret-ish', $line['exceptionMessage']);
        self::assertSame(__FILE__, $line['file']);
        self::assertIsInt($line['line']);
        self::assertArrayNotHasKey('trace', $line);
        self::assertStringNotContainsString('inner-secret', json_encode($line, JSON_THROW_ON_ERROR));
    }

    public function testAProblemThatCannotBeEncodedFallsBackToTheGeneric500(): void
    {
        $response = $this->process(static function (): Response {
            throw new HttpProblem(new \App\Http\Problem\ProblemDetails(400, 'Bad', extensions: ['n' => NAN]));
        });

        self::assertSame(500, $response->status);
    }
}
