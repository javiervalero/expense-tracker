<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\ClientAddress;
use App\Http\Handler;
use App\Http\Middleware\ClientIpMiddleware;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;

final class ClientIpMiddlewareTest extends TestCase
{
    /** @var resource */
    private $stream;

    private ?Request $seen = null;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
        $this->seen = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        rewind($this->stream);
        $lines = [];
        foreach (explode("\n", trim((string) stream_get_contents($this->stream))) as $raw) {
            if ($raw !== '') {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                /** @var array<string, mixed> $decoded */
                $lines[] = $decoded;
            }
        }

        return $lines;
    }

    private function process(Request $request, string $header = 'CloudFront-Viewer-Address'): Response
    {
        $next = new class ($this) implements Handler {
            public function __construct(private ClientIpMiddlewareTest $test) {}

            public function handle(Request $request): Response
            {
                $this->test->saw($request);

                return Response::json(200, ['status' => 'ok']);
            }
        };

        return (new ClientIpMiddleware(new JsonLogger($this->stream), $header))->process($request, $next);
    }

    public function saw(Request $request): void
    {
        $this->seen = $request;
    }

    private function address(): ClientAddress
    {
        self::assertNotNull($this->seen);
        $address = $this->seen->attribute(ClientIpMiddleware::ATTRIBUTE);
        self::assertInstanceOf(ClientAddress::class, $address);

        return $address;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers = []): Request
    {
        return (new Request('GET', '/api/v1/health', [], $headers, ''))->withAttribute(Request::REQUEST_ID, 'rid1');
    }

    public function testAValidHeaderSetsTheAttributeAndLogsNothing(): void
    {
        $response = $this->process($this->request(['cloudfront-viewer-address' => '203.0.113.7:4711']));

        self::assertSame(200, $response->status);
        self::assertSame('203.0.113.7', $this->address()->ip);
        self::assertSame('203.0.113.7', $this->address()->throttleKey);
        self::assertSame([], $this->lines());
    }

    public function testAMissingHeaderIsUnknownLogsOneErrorLineAndDoesNotReject(): void
    {
        $response = $this->process($this->request());

        self::assertSame(200, $response->status);
        self::assertSame('unknown', $this->address()->throttleKey);
        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('error', $lines[0]['level']);
        self::assertSame('client address unavailable', $lines[0]['message']);
        self::assertSame('missing', $lines[0]['reason']);
        self::assertSame('rid1', $lines[0]['requestId']);
        self::assertArrayNotHasKey('value', $lines[0]);
    }

    public function testAnInvalidValueIsLoggedTruncatedTo64Characters(): void
    {
        $value = str_repeat('x', 200);

        $this->process($this->request(['cloudfront-viewer-address' => $value]));

        $lines = $this->lines();
        self::assertSame('too-long', $lines[0]['reason']);
        self::assertSame(str_repeat('x', 64), $lines[0]['value']);
    }

    public function testAMultiValueHeaderIsLoggedWithItsReason(): void
    {
        $this->process($this->request(['cloudfront-viewer-address' => '203.0.113.7:1, 198.51.100.2:2']));

        $lines = $this->lines();
        self::assertSame('multiple-values', $lines[0]['reason']);
        self::assertSame('203.0.113.7:1, 198.51.100.2:2', $lines[0]['value']);
    }

    public function testControlCharactersInTheValueStayOnOneEscapedLine(): void
    {
        $this->process($this->request(['cloudfront-viewer-address' => "1.1.1.1:1\r\n{\"level\":\"info\"}"]));

        rewind($this->stream);
        self::assertSame(1, substr_count((string) stream_get_contents($this->stream), "\n"));
        self::assertSame("1.1.1.1:1\r\n{\"level\":\"info\"}", $this->lines()[0]['value']);
    }

    public function testAValueCutInTheMiddleOfAMultibyteCharacterIsStillLogged(): void
    {
        $this->process($this->request(['cloudfront-viewer-address' => str_repeat('a', 63) . 'é' . 'zz']));

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame(str_repeat('a', 63), $lines[0]['value']);
    }

    public function testOnlyTheConfiguredHeaderIsRead(): void
    {
        $request = $this->request([
            'cloudfront-viewer-address' => '203.0.113.7:1',
            'x-forwarded-for' => '198.51.100.1',
            'x-real-ip' => '198.51.100.2',
            'forwarded' => 'for=198.51.100.3',
        ]);

        $this->process($request, 'X-Custom-Client');

        self::assertSame('unknown', $this->address()->throttleKey);
        self::assertSame('missing', $this->lines()[0]['reason']);
    }

    public function testTheConfiguredHeaderNameIsHonouredCaseInsensitively(): void
    {
        $this->process($this->request(['x-custom-client' => '[::1]:80']), 'X-Custom-Client');

        self::assertSame('::1', $this->address()->ip);
    }

    public function testXForwardedForAloneNeverGivesAnAddress(): void
    {
        $this->process($this->request(['x-forwarded-for' => '198.51.100.1']));

        self::assertNull($this->address()->ip);
    }

    public function testTheLogContextReceivesTheIpOrNull(): void
    {
        $context = new RequestLogContext();
        $this->process($this->request(['cloudfront-viewer-address' => '203.0.113.7:1'])->withAttribute(RequestLogContext::ATTRIBUTE, $context));

        self::assertSame('203.0.113.7', $context->clientIp);
    }

    public function testTheLogContextStaysEmptyWhenTheAddressIsUnknown(): void
    {
        $context = new RequestLogContext();
        $this->process($this->request()->withAttribute(RequestLogContext::ATTRIBUTE, $context));

        self::assertNull($context->clientIp);
    }
}
