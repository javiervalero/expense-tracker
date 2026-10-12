<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LocalAws\Runner\InvocationFailed;
use LocalAws\Runner\RieHttpInvoker;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class RieHttpInvokerTest extends TestCase
{
    private const URL = 'http://worker:8080/2015-03-31/functions/function/invocations';

    public function testPostsTheEventJsonAndReturnsStatusAndBodyWithoutThrowingOnErrorStatuses(): void
    {
        $requests = [];
        $mock = new MockHandler([new Response(500, [], 'boom')]);
        $stack = HandlerStack::create($mock);
        $stack->push(static function (callable $next) use (&$requests): callable {
            return static function (RequestInterface $request, array $options) use ($next, &$requests) {
                $requests[] = [$request, $options];

                return $next($request, $options);
            };
        });

        $result = RieHttpInvoker::create(self::URL, $stack)->invoke('{"Records":[]}');

        self::assertSame(500, $result->status);
        self::assertSame('boom', $result->body);
        self::assertCount(1, $requests);
        [$request, $options] = $requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::URL, (string) $request->getUri());
        self::assertSame('{"Records":[]}', (string) $request->getBody());
        self::assertSame(1, $options['connect_timeout'] ?? null);
        self::assertSame(RieHttpInvoker::TIMEOUT_SECONDS, $options['timeout'] ?? null);
    }

    public function testTheHttpTimeoutIsBelowTheQueuesVisibilityTimeout(): void
    {
        // docker/elasticmq/elasticmq.conf: receipts has defaultVisibilityTimeout = 10 seconds.
        self::assertLessThan(10, RieHttpInvoker::TIMEOUT_SECONDS);
    }

    public function testATransportFailureBecomesInvocationFailedKeepingThePrevious(): void
    {
        $mock = new MockHandler([new ConnectException('cURL error 28: timed out', new Request('POST', self::URL))]);

        try {
            RieHttpInvoker::create(self::URL, HandlerStack::create($mock))->invoke('{}');
            self::fail('expected InvocationFailed');
        } catch (InvocationFailed $failure) {
            self::assertStringContainsString('timed out', $failure->getMessage());
            self::assertInstanceOf(ConnectException::class, $failure->getPrevious());
        }
    }
}
