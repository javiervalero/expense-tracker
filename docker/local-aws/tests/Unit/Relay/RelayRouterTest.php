<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Relay;

use LocalAws\Logging\JsonLogger;
use LocalAws\Relay\RelayHandler;
use LocalAws\Relay\RelayRouter;
use PHPUnit\Framework\TestCase;

final class RelayRouterTest extends TestCase
{
    private const HEADERS = ['Authorization' => 'Bearer t0ken'];
    private const EVENT = '{"Records":[{"a":1}]}';

    private function router(RecordingMessageSender $sender): RelayRouter
    {
        $logger = new JsonLogger(static function (string $line): void {}, null, []);

        return new RelayRouter(new RelayHandler($sender, $logger, 't0ken', 1024));
    }

    public function testHealthAnswers200WithoutAuthenticationAndSendsNothing(): void
    {
        $sender = new RecordingMessageSender();

        $response = $this->router($sender)->route('GET', '/health', [], '');

        self::assertSame(200, $response->status);
        self::assertSame('{"status":"ok"}', $response->body);
        self::assertSame([], $sender->sent);
    }

    public function testHealthOnlyAnswersGet(): void
    {
        $response = $this->router(new RecordingMessageSender())->route('POST', '/health', self::HEADERS, self::EVENT);

        self::assertSame(404, $response->status);
    }

    public function testThePostEndpointIsTheRootPathAndGoesToTheHandler(): void
    {
        $sender = new RecordingMessageSender();

        $response = $this->router($sender)->route('POST', '/', self::HEADERS, self::EVENT);

        self::assertSame(204, $response->status);
        self::assertSame([self::EVENT], $sender->sent);
    }

    public function testRootPathWithAnotherMethodIsStill405(): void
    {
        $response = $this->router(new RecordingMessageSender())->route('GET', '/', self::HEADERS, '');

        self::assertSame(405, $response->status);
    }

    public function testAnyOtherPathIs404EvenWithAValidTokenAndSendsNothing(): void
    {
        $sender = new RecordingMessageSender();

        $response = $this->router($sender)->route('POST', '/other', self::HEADERS, self::EVENT);

        self::assertSame(404, $response->status);
        self::assertSame([], $sender->sent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPaths(): iterable
    {
        yield 'double slash' => ['//x'];
        yield 'double slash only' => ['//'];
        yield 'trailing segment' => ['/health/'];
        yield 'empty path' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unknownPaths')]
    public function testUnknownPathsAre404(string $path): void
    {
        $sender = new RecordingMessageSender();

        $response = $this->router($sender)->route('POST', $path, self::HEADERS, self::EVENT);

        self::assertSame(404, $response->status);
        self::assertSame([], $sender->sent);
    }
}
