<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\FrontController;
use App\Http\ResponseEmitter;
use ErrorException;
use PHPUnit\Framework\TestCase;

final class FrontControllerTest extends TestCase
{
    private const WARNING_SCHEME = 'frontcontrollertest-warning';

    private const ENV = ['ORIGIN_VERIFY_SECRETS' => 'frontcontroller-slot-0123456789-0123456789', 'CLIENT_IP_HEADER' => 'CloudFront-Viewer-Address', 'APP_ORIGINS' => 'http://localhost:5173', 'DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1'];

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
        if (in_array(self::WARNING_SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::WARNING_SCHEME);
        }
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

    /**
     * Registers a stream whose opening raises a PHP warning on any platform (what a failing read does).
     */
    private function warningStream(): string
    {
        self::assertTrue(stream_wrapper_register(self::WARNING_SCHEME, WarningOnOpenStream::class));

        return self::WARNING_SCHEME . '://body';
    }

    private function logged(mixed $stream): string
    {
        self::assertIsResource($stream);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * @return list<string>
     */
    private function logLines(mixed $stream): array
    {
        return array_values(array_filter(
            explode("\n", $this->logged($stream)),
            static fn(string $line): bool => $line !== '',
        ));
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
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/v1/health',
                'QUERY_STRING' => '',
                'HTTP_X_ORIGIN_VERIFY' => self::ENV['ORIGIN_VERIFY_SECRETS'],
            ],
            self::ENV,
            $this->emitter(),
            'data://text/plain,',
            $this->memory(),
        );

        self::assertSame('status 200', $this->calls[0]);
        self::assertContains('body {"status":"ok"}', $this->calls);
    }

    private const VALID_HEADER = 'frontcontroller-slot-0123456789-0123456789';

    public function testServeAnswers403WhenTheOriginHeaderIsMissing(): void
    {
        $log = $this->memory();

        FrontController::serve(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health', 'QUERY_STRING' => ''],
            self::ENV,
            $this->emitter(),
            'data://text/plain,',
            $log,
        );

        self::assertSame('status 403', $this->calls[0]);
    }

    public function testServeAnswers403AndNeverLogsTheSecretWhenTheHeaderIsWrong(): void
    {
        $log = $this->memory();

        FrontController::serve(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health', 'HTTP_X_ORIGIN_VERIFY' => substr(self::VALID_HEADER, 0, 30)],
            self::ENV,
            $this->emitter(),
            'data://text/plain,',
            $log,
        );

        self::assertSame('status 403', $this->calls[0]);
        self::assertStringNotContainsString(self::VALID_HEADER, $this->logged($log));
        self::assertStringNotContainsString(substr(self::VALID_HEADER, 0, 30), $this->logged($log));
    }

    public function testServeAnswers403ForAnOversizedBodyWithoutTheOriginHeader(): void
    {
        $log = $this->memory();

        FrontController::serve(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/health'],
            self::ENV,
            $this->emitter(),
            'data://text/plain,' . str_repeat('a', 70000),
            $log,
        );

        self::assertSame('status 403', $this->calls[0]);
        $this->assertOneRequestLineAndTheOriginWarning($log, 403);
    }

    public function testServeAnswers403ForAMalformedQueryWithoutTheOriginHeader(): void
    {
        $log = $this->memory();

        FrontController::serve(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health?a[=1', 'QUERY_STRING' => 'a[=1'],
            self::ENV,
            $this->emitter(),
            'data://text/plain,',
            $log,
        );

        self::assertSame('status 403', $this->calls[0]);
        $this->assertOneRequestLineAndTheOriginWarning($log, 403);
    }

    public function testServeAnswersAnOversizedBodyWith413AndLogsItOnce(): void
    {
        $log = $this->memory();

        FrontController::serve(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/health', 'HTTP_X_ORIGIN_VERIFY' => self::VALID_HEADER],
            self::ENV,
            $this->emitter(),
            'data://text/plain,' . str_repeat('a', 70000),
            $log,
        );

        self::assertSame('status 413', $this->calls[0]);
        $lines = $this->logLines($log);
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true);
        self::assertIsArray($entry);
        self::assertSame('request', $entry['message']);
        self::assertSame(413, $entry['status']);
    }

    public function testServeLogsAMalformedQueryAs400Once(): void
    {
        $log = $this->memory();

        FrontController::serve(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health?a[=1', 'QUERY_STRING' => 'a[=1', 'HTTP_X_ORIGIN_VERIFY' => self::VALID_HEADER],
            self::ENV,
            $this->emitter(),
            'data://text/plain,',
            $log,
        );

        self::assertSame('status 400', $this->calls[0]);
        $lines = $this->logLines($log);
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true);
        self::assertIsArray($entry);
        self::assertSame('request', $entry['message']);
        self::assertSame(400, $entry['status']);
    }

    /**
     * @param resource $log
     */
    private function assertOneRequestLineAndTheOriginWarning(mixed $log, int $status): void
    {
        $messages = array_map(static function (string $line): array {
            $entry = json_decode($line, true);
            self::assertIsArray($entry);

            return [$entry['message'], $entry['status'] ?? null];
        }, $this->logLines($log));

        self::assertContains(['request', $status], $messages);
        self::assertCount(1, array_filter($messages, static fn(array $m): bool => $m[0] === 'request'));
        self::assertCount(1, array_filter($messages, static fn(array $m): bool => $m[0] === 'origin verification failed'));
        self::assertCount(2, $messages);
    }

    public function testAnyThrowableFromStartupOrReadingIsAConstant500WithTheDefaultHeaders(): void
    {
        // Opening the body warns; the installed handler turns that into an exception.
        $before = set_error_handler(static fn(): bool => false);
        restore_error_handler();
        $log = $this->memory();
        FrontController::serve(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health'],
            [],
            $this->emitter(),
            $this->warningStream(),
            $log,
        );
        $after = set_error_handler(static fn(): bool => false);
        restore_error_handler();
        self::assertSame($before, $after);

        self::assertSame('status 500', $this->calls[0]);
        self::assertContains('header Cache-Control: no-store', $this->calls);
        self::assertContains('header X-Content-Type-Options: nosniff', $this->calls);
        self::assertContains('header Content-Type: application/problem+json', $this->calls);
        self::assertContains(
            'body {"type":"about:blank","title":"Internal Server Error","status":500}',
            $this->calls,
        );
    }

    public function testTheCatchAllLogsTheExceptionClassOnlyToTheLogStream(): void
    {
        $log = $this->memory();

        FrontController::serve(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/health'],
            [],
            $this->emitter(),
            $this->warningStream(),
            $log,
        );

        self::assertSame(
            '{"level":"error","message":"front controller failed","exception":"ErrorException"}' . "\n",
            $this->logged($log),
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

/**
 * A stream that cannot be opened: raises a warning, as a failing read does, whatever the operating system.
 */
final class WarningOnOpenStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        trigger_error('cannot open the body', E_USER_WARNING);

        return false;
    }
}
