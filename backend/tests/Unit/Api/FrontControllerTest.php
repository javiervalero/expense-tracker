<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\FrontController;
use App\Http\ResponseEmitter;
use ErrorException;
use PHPUnit\Framework\TestCase;

final class FrontControllerTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private int $errorReporting = 0;

    protected function setUp(): void
    {
        $this->calls = [];
        // PHPUnit lowers error_reporting while it runs; production has E_ALL.
        $this->errorReporting = error_reporting(E_ALL);
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
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

    private function emitter(): ResponseEmitter
    {
        return new ResponseEmitter(
            function (int $status): void {
                $this->calls[] = 'status ' . $status;
            },
            function (string $line, bool $replace): void {
                $this->calls[] = 'header ' . $line;
            },
            function (string $chunk): void {
                $this->calls[] = 'body ' . $chunk;
            },
        );
    }

    public function testAnErrorBecomesAnErrorException(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('boom');

        FrontController::handleError(E_WARNING, 'boom', 'file.php', 7);
    }

    public function testASilencedErrorIsLeftToPhp(): void
    {
        $before = error_reporting(0);
        try {
            self::assertFalse(FrontController::handleError(E_WARNING, 'quiet', 'file.php', 7));
        } finally {
            error_reporting($before);
        }
    }

    public function testAnAtSignedCallDoesNotThrowWhileTheHandlerIsInstalled(): void
    {
        $stream = fopen('php://memory', 'rb');
        self::assertIsResource($stream);
        set_error_handler([FrontController::class, 'handleError']);
        try {
            $written = @fwrite($stream, 'x');
        } finally {
            restore_error_handler();
        }

        self::assertFalse($written);
    }

    public function testServeAnswersTheKernelResponse(): void
    {
        FrontController::serve(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health', 'QUERY_STRING' => ''],
            [],
            $this->emitter(),
            'data://text/plain,',
            $this->memory(),
        );

        self::assertSame('status 200', $this->calls[0]);
        self::assertContains('body {"status":"ok"}', $this->calls);
    }

    public function testServeAnswersAnOversizedBodyWith413(): void
    {
        FrontController::serve(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/health'],
            [],
            $this->emitter(),
            'data://text/plain,' . str_repeat('a', 70000),
            $this->memory(),
        );

        self::assertSame('status 413', $this->calls[0]);
    }

    public function testAnyThrowableFromStartupOrReadingIsAConstant500WithTheDefaultHeaders(): void
    {
        // Reading a directory warns; the installed handler turns that into an exception.
        FrontController::serve(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health'],
            [],
            $this->emitter(),
            sys_get_temp_dir(),
            $this->memory(),
        );

        self::assertSame('status 500', $this->calls[0]);
        self::assertContains('header Cache-Control: no-store', $this->calls);
        self::assertContains('header X-Content-Type-Options: nosniff', $this->calls);
        self::assertContains('header Content-Type: application/problem+json', $this->calls);
        self::assertContains(
            'body {"type":"about:blank","title":"Internal Server Error","status":500}',
            $this->calls,
        );
    }

    public function testTheErrorHandlerIsRemovedAfterServing(): void
    {
        $before = set_error_handler(static fn(): bool => false);
        restore_error_handler();

        FrontController::serve(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health'],
            [],
            $this->emitter(),
            'data://text/plain,',
            $this->memory(),
        );

        $after = set_error_handler(static fn(): bool => false);
        restore_error_handler();
        self::assertSame($before, $after);
    }
}
