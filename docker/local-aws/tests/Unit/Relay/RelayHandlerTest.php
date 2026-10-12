<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Relay;

use LocalAws\Logging\JsonLogger;
use LocalAws\Relay\RelayHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelayHandlerTest extends TestCase
{
    private const TOKEN = 'unit-test-webhook-token';
    private const FIXTURES = __DIR__ . '/../../Fixtures/s3-events/';

    /** @var list<string> */
    private array $logLines = [];

    private function handler(RecordingMessageSender $sender, int $maxBodyBytes = 262144): RelayHandler
    {
        $this->logLines = [];
        $logger = new JsonLogger(function (string $line): void {
            $this->logLines[] = $line;
        }, null, [self::TOKEN]);

        return new RelayHandler($sender, $logger, self::TOKEN, $maxBodyBytes);
    }

    private static function fixture(string $name): string
    {
        $content = file_get_contents(self::FIXTURES . $name);
        self::assertIsString($content);

        return $content;
    }

    /**
     * @return array<string, string>
     */
    private static function bearer(string $token = self::TOKEN): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodeAssoc(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function decodeTree(string $json): \stdClass
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);

        return $decoded;
    }

    #[DataProvider('fixturesWithRecords')]
    public function testForwardsExactlyTheRecordsOfAnEventAndAnswers204(string $fixture): void
    {
        $sender = new RecordingMessageSender();
        $body = self::fixture($fixture);

        $response = $this->handler($sender)->handle('POST', self::bearer(), $body);

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertCount(1, $sender->sent);
        self::assertSame(['Records'], array_keys(self::decodeAssoc($sender->sent[0])), 'only Records is forwarded');
        // Object trees (assoc=false) keep the difference between {} and [] that assoc arrays would lose.
        self::assertEquals(
            self::decodeTree($body)->Records,
            self::decodeTree($sender->sent[0])->Records,
            'the records are unchanged',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixturesWithRecords(): iterable
    {
        yield 'silo (MinIO fork)' => ['silo-object-created-post.json'];
        yield 'AWS' => ['aws-object-created-post.json'];
    }

    public function testKeepsThePercentEncodedKeyVerbatimWithoutDecodingOrDoubleEncoding(): void
    {
        $sender = new RecordingMessageSender();

        $this->handler($sender)->handle('POST', self::bearer(), self::fixture('silo-object-created-post.json'));

        self::assertStringContainsString('"key":"receipts%2Fuser-fe70a955%2Fexpense-fe70a955%2Freceipt-fe70a955"', $sender->sent[0]);
        self::assertStringNotContainsString('%252F', $sender->sent[0]);
        self::assertStringNotContainsString('\/', $sender->sent[0], 'slashes are not escaped');
    }

    public function testDropsTheEmulatorWrapperFieldsButKeepsEmulatorSpecificFieldsInsideTheRecords(): void
    {
        $sender = new RecordingMessageSender();

        $this->handler($sender)->handle('POST', self::bearer(), self::fixture('silo-object-created-post.json'));

        $sent = self::decodeAssoc($sender->sent[0]);
        self::assertArrayNotHasKey('EventName', $sent);
        self::assertArrayNotHasKey('Key', $sent);
        self::assertIsArray($sent['Records']);
        $record = $sent['Records'][0];
        self::assertIsArray($record);
        self::assertSame('minio:s3', $record['eventSource']);
        self::assertSame('', $record['awsRegion']);
    }

    public function testKeepsEmptyJsonObjectsAsObjectsAndUnicodeUnescaped(): void
    {
        $sender = new RecordingMessageSender();
        $body = '{"Records":[{"s3":{"object":{"key":"a%20b","userMetadata":{}}},"note":"café 😀"}]}';

        $this->handler($sender)->handle('POST', self::bearer(), $body);

        self::assertSame('{"Records":[{"s3":{"object":{"key":"a%20b","userMetadata":{}}},"note":"café 😀"}]}', $sender->sent[0]);
    }

    public function testForwardsTheExactTextForEmptyObjectsEmptyListsAndScalarTypes(): void
    {
        $sender = new RecordingMessageSender();
        $body = '{"Records":[{"o":{},"l":[],"zero":0,"one":1.0,"half":0.5,"nothing":null,"empty":"","yes":true,"nested":[{},[]]}]}';

        $response = $this->handler($sender)->handle('POST', self::bearer(), $body);

        self::assertSame(204, $response->status);
        self::assertSame([$body], $sender->sent);
    }

    public function testDoesNotDeduplicateTheSameEventDeliveredTwice(): void
    {
        $sender = new RecordingMessageSender();
        $handler = $this->handler($sender);
        $body = self::fixture('silo-object-created-post.json');

        $handler->handle('POST', self::bearer(), $body);
        $handler->handle('POST', self::bearer(), $body);

        self::assertCount(2, $sender->sent);
        self::assertSame($sender->sent[0], $sender->sent[1]);
    }

    public function testForwardsEveryRecordOfAMultiRecordEventInOneMessage(): void
    {
        $sender = new RecordingMessageSender();

        $this->handler($sender)->handle('POST', self::bearer(), '{"Records":[{"a":1},{"b":2}]}');

        self::assertSame(['{"Records":[{"a":1},{"b":2}]}'], $sender->sent);
    }

    #[DataProvider('otherMethods')]
    public function testRefusesAnyMethodButPostWith405AndAllowHeader(string $method): void
    {
        $sender = new RecordingMessageSender();

        $response = $this->handler($sender)->handle($method, self::bearer(), self::fixture('silo-object-created-post.json'));

        self::assertSame(405, $response->status);
        self::assertSame(['Allow' => 'POST'], $response->headers);
        self::assertSame([], $sender->sent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherMethods(): iterable
    {
        foreach (['GET', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS', 'post'] as $method) {
            yield $method => [$method];
        }
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('badAuthorization')]
    public function testRefusesAMissingOrWrongBearerTokenWith401AndSendsNothing(array $headers): void
    {
        $sender = new RecordingMessageSender();

        $response = $this->handler($sender)->handle('POST', $headers, self::fixture('silo-object-created-post.json'));

        self::assertSame(401, $response->status);
        self::assertSame(['WWW-Authenticate' => 'Bearer'], $response->headers);
        self::assertSame([], $sender->sent);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function badAuthorization(): iterable
    {
        yield 'no header' => [[]];
        yield 'empty header' => [['Authorization' => '']];
        yield 'wrong token' => [self::bearer('not-the-token')];
        yield 'token prefix' => [self::bearer(substr(self::TOKEN, 0, 5))];
        yield 'token plus suffix' => [self::bearer(self::TOKEN . 'x')];
        yield 'token only, no scheme' => [['Authorization' => self::TOKEN]];
        yield 'basic scheme' => [['Authorization' => 'Basic ' . self::TOKEN]];
        yield 'empty bearer' => [['Authorization' => 'Bearer ']];
        yield 'token with inner space' => [['Authorization' => 'Bearer ' . self::TOKEN . ' extra']];
        yield 'token with trailing newline' => [['Authorization' => "Bearer " . self::TOKEN . "\n"]];
        yield 'bearer without space' => [['Authorization' => 'Bearer' . self::TOKEN]];
    }

    public function testFindsTheAuthorizationHeaderWhateverItsCaseAndAcceptsALowercaseScheme(): void
    {
        $sender = new RecordingMessageSender();

        $response = $this->handler($sender)->handle(
            'POST',
            ['authorization' => 'bearer ' . self::TOKEN],
            self::fixture('silo-object-created-post.json'),
        );

        self::assertSame(204, $response->status);
    }

    public function testChecksTheTokenBeforeTheBody(): void
    {
        $response = $this->handler(new RecordingMessageSender())->handle('POST', [], 'not json');

        self::assertSame(401, $response->status);
    }

    public function testRefusesABodyOverTheLimitWith413ButAcceptsOneExactlyAtTheLimit(): void
    {
        $sender = new RecordingMessageSender();
        $body = '{"Records":[{"a":1}]}';
        $handler = $this->handler($sender, strlen($body));

        $atLimit = $handler->handle('POST', self::bearer(), $body);
        $overLimit = $handler->handle('POST', self::bearer(), $body . ' ');

        self::assertSame(204, $atLimit->status);
        self::assertSame(413, $overLimit->status);
        self::assertCount(1, $sender->sent);
    }

    #[DataProvider('invalidBodies')]
    public function testRefusesABodyThatIsNotAnEventWithRecordsWith400(string $body): void
    {
        $sender = new RecordingMessageSender();

        $response = $this->handler($sender)->handle('POST', self::bearer(), $body);

        self::assertSame(400, $response->status);
        self::assertSame([], $sender->sent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty body' => [''];
        yield 'not JSON' => ['<xml/>'];
        yield 'truncated JSON' => ['{"Records":[{"a":1}'];
        yield 'invalid UTF-8' => ["{\"Records\":[{\"a\":\"\xff\"}]}"];
        yield 'JSON null' => ['null'];
        yield 'JSON string' => ['"Records"'];
        yield 'JSON list' => ['[{"a":1}]'];
        yield 'no Records' => ['{"Event":"s3:TestEvent"}'];
        yield 'S3 test event' => [self::fixture('aws-s3-test-event.json')];
        yield 'Records null' => ['{"Records":null}'];
        yield 'Records empty list' => ['{"Records":[]}'];
        yield 'Records an object' => ['{"Records":{"a":1}}'];
        yield 'Records a string' => ['{"Records":"x"}'];
        yield 'a record that is not an object' => ['{"Records":[{"a":1},"x"]}'];
        yield 'nested too deep' => [str_repeat('[', 600) . str_repeat(']', 600)];
    }

    public function testAnswers502WhenTheQueueRefusesSoTheEmulatorRetries(): void
    {
        $sender = new RecordingMessageSender(fails: true);

        $response = $this->handler($sender)->handle('POST', self::bearer(), self::fixture('silo-object-created-post.json'));

        self::assertSame(502, $response->status);
        self::assertCount(1, $sender->sent);
    }

    public function testLogsEventCountAndKeysAsJsonLinesOnSuccess(): void
    {
        $handler = $this->handler(new RecordingMessageSender());

        $handler->handle('POST', self::bearer(), self::fixture('silo-object-created-post.json'));

        self::assertCount(1, $this->logLines);
        $entry = self::decodeAssoc($this->logLines[0]);
        self::assertSame('info', $entry['level']);
        self::assertSame('event forwarded to the queue', $entry['message']);
        self::assertSame(1, $entry['records']);
        self::assertSame(['receipts%2Fuser-fe70a955%2Fexpense-fe70a955%2Freceipt-fe70a955'], $entry['keys']);
    }

    public function testLogsAFailureWithoutTheBody(): void
    {
        $handler = $this->handler(new RecordingMessageSender(fails: true));

        $handler->handle('POST', self::bearer(), self::fixture('silo-object-created-post.json'));

        $entry = self::decodeAssoc($this->logLines[0]);
        self::assertSame('error', $entry['level']);
        self::assertSame('queue down', $entry['error']);
        self::assertSame(1, $entry['records']);
    }

    public function testNeverLogsTheTokenOrTheAuthorizationHeaderWhateverTheOutcome(): void
    {
        $body = self::fixture('silo-object-created-post.json');
        $handler = $this->handler(new RecordingMessageSender(fails: true));

        $handler->handle('POST', self::bearer(), $body);
        $handler->handle('POST', self::bearer('wrong-token-value'), $body);
        $handler->handle('POST', self::bearer(), 'not json');
        $handler->handle('GET', self::bearer(), '');

        $log = implode('', $this->logLines);
        self::assertNotSame('', $log);
        self::assertStringNotContainsString(self::TOKEN, $log);
        self::assertStringNotContainsString('wrong-token-value', $log);
        self::assertStringNotContainsStringIgnoringCase('bearer', $log);
    }

    public function testLogsEveryRefusalWithItsStatus(): void
    {
        $handler = $this->handler(new RecordingMessageSender());

        $handler->handle('GET', [], '');
        $handler->handle('POST', [], '{}');
        $handler->handle('POST', self::bearer(), '{}');

        $statuses = array_map(static fn(string $line): mixed => self::decodeAssoc($line)['status'] ?? null, $this->logLines);
        self::assertSame([405, 401, 400], $statuses);
    }

    public function testLogsRefusalsAtWarningLevelAndTheQueueFailureAtError(): void
    {
        $handler = $this->handler(new RecordingMessageSender(fails: true));

        $handler->handle('GET', [], '');
        $handler->handle('POST', [], '{}');
        $handler->handle('POST', self::bearer(), '{}');
        $handler->handle('POST', self::bearer(), self::fixture('silo-object-created-post.json'));

        $levels = array_map(static fn(string $line): mixed => self::decodeAssoc($line)['level'] ?? null, $this->logLines);
        self::assertSame(['warning', 'warning', 'warning', 'error'], $levels);
    }

    public function testCapsTheLoggedMethodStringLength(): void
    {
        $handler = $this->handler(new RecordingMessageSender());

        $handler->handle(str_repeat('A', 5000), [], '');

        $entry = self::decodeAssoc($this->logLines[0]);
        self::assertIsString($entry['method']);
        self::assertLessThanOrEqual(32, strlen($entry['method']));
        self::assertStringStartsWith('AAAA', $entry['method']);
    }

    public function testRefusesAContentLengthOverTheLimitWith413WithoutReadingTheBody(): void
    {
        $sender = new RecordingMessageSender();
        $bodyWasRead = false;
        $readBody = static function () use (&$bodyWasRead): string {
            $bodyWasRead = true;

            return '';
        };

        $response = $this->handler($sender, 100)->handle('POST', self::bearer() + ['Content-Length' => '101'], $readBody);

        self::assertSame(413, $response->status);
        self::assertFalse($bodyWasRead, 'the body is not read once the declared length is over the cap');
        self::assertSame([], $sender->sent);
    }

    public function testContentLengthHeaderIsFoundWhateverItsCaseAndAcceptedAtTheLimit(): void
    {
        $sender = new RecordingMessageSender();
        $body = '{"Records":[{"a":1}]}';

        $response = $this->handler($sender, strlen($body))->handle(
            'POST',
            self::bearer() + ['content-length' => (string) strlen($body)],
            static fn(): string => $body,
        );

        self::assertSame(204, $response->status);
    }

    public function testAMissingContentLengthIsAcceptedUpToTheReadCap(): void
    {
        $sender = new RecordingMessageSender();
        $body = '{"Records":[{"a":1}]}';
        $handler = $this->handler($sender, strlen($body));

        $accepted = $handler->handle('POST', self::bearer(), static fn(): string => $body);
        $tooLong = $handler->handle('POST', self::bearer(), static fn(): string => $body . ' ');

        self::assertSame(204, $accepted->status);
        self::assertSame(413, $tooLong->status);
    }

    #[DataProvider('unusableContentLengths')]
    public function testIgnoresAContentLengthThatIsNotAPlainNumberAndFallsBackToTheReadCap(string $value): void
    {
        $sender = new RecordingMessageSender();

        $response = $this->handler($sender)->handle(
            'POST',
            self::bearer() + ['Content-Length' => $value],
            static fn(): string => '{"Records":[{"a":1}]}',
        );

        self::assertSame(204, $response->status);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableContentLengths(): iterable
    {
        yield 'empty' => [''];
        yield 'negative' => ['-5'];
        yield 'text' => ['abc'];
        yield 'with unit' => ['10 KB'];
    }

    public function testChecksTheTokenBeforeTheDeclaredLength(): void
    {
        $response = $this->handler(new RecordingMessageSender(), 10)->handle('POST', ['Content-Length' => '999999'], static fn(): string => '');

        self::assertSame(401, $response->status);
    }
}
