<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Tests\Support\LocalAws;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Client as HttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The `s3-events-relay` HTTP surface, called directly from the php tools container (ADR 0007, WP9): the status codes
 * that the unit tests prove for the handler, here proven through the real `php -S` server, its limits and its
 * read-only container. Run it through `make test-integration` (the stack must be up).
 *
 * Like S3ToQueueTest it reads (and purges) the real `receipts` queue, so the worker runner (WP11) must be stopped.
 */
final class RelayHttpTest extends TestCase
{
    /** Same cap as MAX_BODY_BYTES in docker/local-aws/bin/relay.php (256 KiB). */
    private const MAX_BODY_BYTES = 262144;
    private const MESSAGE_TIMEOUT_SECONDS = 15;

    private HttpClient $http;
    private SqsClient $sqs;
    private string $relayUrl;
    private string $token;
    private string $queueUrl;

    protected function setUp(): void
    {
        $this->sqs = LocalAws::fromGlobals()->sqs();
        $this->http = new HttpClient(['http_errors' => false, 'timeout' => 20]);
        $this->relayUrl = 'http://s3-events-relay:8080';

        $token = getenv('S3_WEBHOOK_AUTH_TOKEN');
        self::assertIsString($token, 'S3_WEBHOOK_AUTH_TOKEN must be set (see .env.example)');
        $this->token = $token;
        $queueUrl = getenv('SQS_RECEIPTS_QUEUE_URL');
        self::assertIsString($queueUrl, 'SQS_RECEIPTS_QUEUE_URL must be set (see .env.example)');
        $this->queueUrl = $queueUrl;
        $this->sqs->purgeQueue(['QueueUrl' => $this->queueUrl]);
    }

    protected function tearDown(): void
    {
        $this->sqs->purgeQueue(['QueueUrl' => $this->queueUrl]);
    }

    public function testGetIsRefusedWith405AndAllowPost(): void
    {
        $response = $this->http->get($this->relayUrl . '/');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function testPostWithoutTokenIsRefusedWith401(): void
    {
        $response = $this->http->post($this->relayUrl . '/', ['body' => '{"Records":[{"a":1}]}']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testPostWithAWrongTokenIsRefusedWith401(): void
    {
        $response = $this->post('{"Records":[{"a":1}]}', 'not-the-token');

        self::assertSame(401, $response->getStatusCode());
    }

    public function testABodyOverTheCapIsRefusedWith413AndNothingIsQueued(): void
    {
        $body = '{"Records":[{"pad":"' . str_repeat('x', 300_000) . '"}]}';

        $response = $this->post($body);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame([], $this->receive(), 'nothing reached the queue');
    }

    public function testABodyJustUnderTheCapIsAcceptedEvenThoughPhpBuffersItToTmp(): void
    {
        // Over php -S's ~64 KiB in-memory threshold: it needs the tmpfs /tmp of the container (the root is read-only).
        $overhead = strlen('{"Records":[{"pad":""}]}');
        $body = '{"Records":[{"pad":"' . str_repeat('x', self::MAX_BODY_BYTES - $overhead) . '"}]}';
        self::assertSame(self::MAX_BODY_BYTES, strlen($body));

        $response = $this->post($body);

        self::assertSame(204, $response->getStatusCode());
        $messages = $this->awaitMessages(1);
        self::assertSame($body, $messages[0]);
    }

    public function testABodyWithoutRecordsIsRefusedWith400(): void
    {
        $response = $this->post('{}');

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAValidEventWithEncodedKeyIsQueuedUnchanged(): void
    {
        $sent = '{"Records":[{"eventSource":"minio:s3","s3":{"object":{"key":"receipts%2Fuser+a%2Fexp%2B1%2Freceipt","userMetadata":{}}},"tags":[],"size":0}]}';

        $response = $this->post($sent);

        self::assertSame(204, $response->getStatusCode());
        $messages = $this->awaitMessages(1);
        self::assertCount(1, $messages);
        self::assertEquals(
            json_decode($sent, false, 512, JSON_THROW_ON_ERROR),
            json_decode($messages[0], false, 512, JSON_THROW_ON_ERROR),
            'semantically what was sent, with {} and [] preserved',
        );
        self::assertStringContainsString('"key":"receipts%2Fuser+a%2Fexp%2B1%2Freceipt"', $messages[0], 'the key is neither decoded nor double-encoded');
    }

    public function testHealthAnswers200WithoutAuthentication(): void
    {
        $response = $this->http->get($this->relayUrl . '/health');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testADoubleSlashPathIs404AndNotTheRoot(): void
    {
        $response = $this->post('{"Records":[{"a":1}]}', null, '//x');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->receive());
    }

    private function post(string $body, ?string $token = null, string $path = '/'): ResponseInterface
    {
        return $this->http->post($this->relayUrl . $path, [
            'body' => $body,
            'headers' => ['Authorization' => 'Bearer ' . ($token ?? $this->token), 'Content-Type' => 'application/json'],
        ]);
    }

    /**
     * @return list<string> the message bodies (deleted from the queue)
     */
    private function receive(int $waitSeconds = 1): array
    {
        $result = $this->sqs->receiveMessage(['QueueUrl' => $this->queueUrl, 'MaxNumberOfMessages' => 10, 'WaitTimeSeconds' => $waitSeconds]);
        $messages = $result->get('Messages') ?? [];
        self::assertIsArray($messages);
        $bodies = [];
        foreach ($messages as $message) {
            self::assertIsArray($message);
            self::assertIsString($message['ReceiptHandle'] ?? null);
            self::assertIsString($message['Body'] ?? null);
            $this->sqs->deleteMessage(['QueueUrl' => $this->queueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
            $bodies[] = $message['Body'];
        }

        return $bodies;
    }

    /**
     * @return list<string>
     */
    private function awaitMessages(int $count): array
    {
        $bodies = [];
        $deadline = microtime(true) + self::MESSAGE_TIMEOUT_SECONDS;
        while (count($bodies) < $count && microtime(true) < $deadline) {
            $bodies = [...$bodies, ...$this->receive()];
        }
        self::assertCount($count, $bodies, 'the queue did not get the message in time (is worker-runner consuming it?)');

        return $bodies;
    }
}
