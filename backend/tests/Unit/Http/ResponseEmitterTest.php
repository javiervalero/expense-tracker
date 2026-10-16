<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Response;
use App\Http\ResponseEmitter;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ResponseEmitterTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
    }

    public function testWritesStatusHeadersAndBodyInThatOrder(): void
    {
        $response = Response::json(201, ['a' => 1]);

        $this->emitter()->emit($response, 'POST');

        self::assertSame(
            ['status 201', 'header Content-Type: application/json (replace)', 'body {"a":1}'],
            $this->calls,
        );
    }

    public function testRepeatedHeadersAreAddedNotReplaced(): void
    {
        $response = Response::empty(200)->withAddedHeader('Set-Cookie', 'a=1')->withAddedHeader('Set-Cookie', 'b=2');

        $this->emitter()->emit($response, 'GET');

        self::assertSame(
            ['status 200', 'header Set-Cookie: a=1 (replace)', 'header Set-Cookie: b=2 (add)'],
            $this->calls,
        );
    }

    public function testHeadGetsStatusAndHeadersButNoBody(): void
    {
        $this->emitter()->emit(Response::json(200, ['a' => 1]), 'HEAD');

        self::assertSame(['status 200', 'header Content-Type: application/json (replace)'], $this->calls);
    }

    public function testAnEmptyBodyIsNotWritten(): void
    {
        $this->emitter()->emit(Response::empty(204), 'DELETE');

        self::assertSame(['status 204'], $this->calls);
    }

    public function testALowerCaseHeadIsNotTreatedAsHead(): void
    {
        $this->emitter()->emit(Response::json(200, []), 'head');

        self::assertContains('body []', $this->calls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheNativeEmitterUsesPhpsOwnOutputFunctions(): void
    {
        ob_start();
        ResponseEmitter::native()->emit(Response::json(201, ['a' => 1]), 'POST');
        $output = ob_get_clean();

        self::assertSame('{"a":1}', $output);
        self::assertSame(201, http_response_code());
    }

    private function emitter(): ResponseEmitter
    {
        return new ResponseEmitter(
            function (int $status): void {
                $this->calls[] = 'status ' . $status;
            },
            function (string $line, bool $replace): void {
                $this->calls[] = 'header ' . $line . ($replace ? ' (replace)' : ' (add)');
            },
            function (string $chunk): void {
                $this->calls[] = 'body ' . $chunk;
            },
        );
    }
}
