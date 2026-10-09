<?php

declare(strict_types=1);

namespace App\Tests\Integration\Worker;

use App\Queue\MalformedSqsEvent;
use GuzzleHttp\Client as HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The real worker image (Bref php-85 in function mode, WP10) invoked through its Runtime Interface Emulator, from the
 * php tools container on the Compose network. Run it through `make test-integration` (the stack must be up).
 *
 * It pins down the two answers the worker runner (WP11) relies on: a normal invocation returns `batchItemFailures`,
 * and a failing invocation is still HTTP 200, with `errorType` and `errorMessage` in the body and no
 * `X-Amz-Function-Error` header (ADR 0012, "Verified in Phase 0").
 */
final class RieInvocationTest extends TestCase
{
    private const INVOCATION_URL = 'http://worker:8080/2015-03-31/functions/function/invocations';
    private const FIXTURE = __DIR__ . '/../../Fixtures/s3-events/silo-object-created-post.json';

    private HttpClient $http;

    protected function setUp(): void
    {
        $this->http = new HttpClient(['http_errors' => false, 'timeout' => 30]);
    }

    /**
     * @param array<array-key, mixed> $event
     *
     * @return array{int, string, array<array-key, mixed>} status, the X-Amz-Function-Error header, the decoded body
     */
    private function invoke(array $event): array
    {
        $response = $this->http->post(self::INVOCATION_URL, ['body' => json_encode($event, JSON_THROW_ON_ERROR)]);
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'the body is a JSON object');

        return [$response->getStatusCode(), $response->getHeaderLine('X-Amz-Function-Error'), $decoded];
    }

    public function testATwoRecordBatchReportsOnlyTheMalformedMessage(): void
    {
        $validBody = file_get_contents(self::FIXTURE);
        self::assertIsString($validBody);
        $event = ['Records' => [
            ['messageId' => 'valid-message', 'receiptHandle' => 'h1', 'body' => $validBody, 'eventSource' => 'aws:sqs'],
            ['messageId' => 'malformed-message', 'receiptHandle' => 'h2', 'body' => 'this is not an S3 event', 'eventSource' => 'aws:sqs'],
        ]];

        [$status, , $body] = $this->invoke($event);

        self::assertSame(200, $status);
        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'malformed-message']]], $body);
    }

    public function testABatchOfOnlyValidMessagesHasNoFailures(): void
    {
        $validBody = file_get_contents(self::FIXTURE);
        self::assertIsString($validBody);
        $event = ['Records' => [['messageId' => 'only-one', 'body' => $validBody]]];

        [$status, , $body] = $this->invoke($event);

        self::assertSame(200, $status);
        self::assertSame(['batchItemFailures' => []], $body);
    }

    public function testAnEventWithoutRecordsFailsTheInvocationWithTheErrorShapeAndHttp200(): void
    {
        [$status, $functionErrorHeader, $body] = $this->invoke(['not' => 'an sqs event']);

        self::assertSame(200, $status, 'under RIE a failed invocation is still HTTP 200');
        self::assertSame('', $functionErrorHeader, 'and carries no X-Amz-Function-Error header');
        self::assertArrayNotHasKey('batchItemFailures', $body);
        self::assertIsString($body['errorType'] ?? null);
        self::assertSame(MalformedSqsEvent::class, $body['errorType']);
        self::assertIsString($body['errorMessage'] ?? null);
        self::assertNotSame('', $body['errorMessage']);
    }
}
