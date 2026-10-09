<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts;

use App\Infrastructure\Logging\JsonLogger;
use App\Receipts\InvalidS3Event;
use App\Receipts\ReceivedReceiptLogger;
use App\Receipts\S3EventParser;
use PHPUnit\Framework\TestCase;

final class ReceivedReceiptLoggerTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    private function processor(): ReceivedReceiptLogger
    {
        return new ReceivedReceiptLogger(new S3EventParser(), new JsonLogger($this->stream));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function entries(): array
    {
        rewind($this->stream);
        $entries = [];
        foreach (explode("\n", trim((string) stream_get_contents($this->stream))) as $line) {
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($entry);
            $entries[] = $entry;
        }

        return $entries;
    }

    public function testLogsTheBucketAndTheDecodedKey(): void
    {
        $body = '{"Records":[{"s3":{"bucket":{"name":"receipts"},"object":{"key":"receipts%2Fu+1%2Fe%2Fr"}}}]}';

        $this->processor()->process(['messageId' => 'm1', 'body' => $body]);

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame('info', $entries[0]['level']);
        self::assertSame('receipt received', $entries[0]['message']);
        self::assertSame('receipts', $entries[0]['bucket']);
        self::assertSame('receipts/u 1/e/r', $entries[0]['key']);
        self::assertSame('m1', $entries[0]['messageId']);
    }

    public function testLogsOneLinePerObject(): void
    {
        $body = '{"Records":[{"s3":{"bucket":{"name":"b"},"object":{"key":"a"}}},{"s3":{"bucket":{"name":"b"},"object":{"key":"c"}}}]}';

        $this->processor()->process(['messageId' => 'm1', 'body' => $body]);

        self::assertSame(['a', 'c'], array_column($this->entries(), 'key'));
    }

    public function testAnS3TestEventIsAcceptedAndLoggedAsSuch(): void
    {
        $this->processor()->process(['messageId' => 'm1', 'body' => '{"Service":"Amazon S3","Event":"s3:TestEvent"}']);

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame('s3 event without objects ignored', $entries[0]['message']);
        self::assertArrayNotHasKey('key', $entries[0]);
    }

    public function testAMalformedBodyThrowsSoThatTheMessageIsReportedAndRedriven(): void
    {
        $this->expectException(InvalidS3Event::class);

        try {
            $this->processor()->process(['messageId' => 'm1', 'body' => 'not json']);
        } finally {
            self::assertSame([], $this->entries(), 'nothing is logged as received');
        }
    }

    public function testARecordWithoutAStringBodyThrows(): void
    {
        $this->expectException(InvalidS3Event::class);

        $this->processor()->process(['messageId' => 'm1']);
    }
}
