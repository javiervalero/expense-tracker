<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Infrastructure\Config\InvalidConfiguration;
use App\Queue\SqsBatchProcessor;
use App\Receipts\Processing\ReceiptProcessor;
use App\Worker\WorkerFactory;
use PHPUnit\Framework\TestCase;

final class WorkerFactoryTest extends TestCase
{
    private const ENV = [
        'APP_ENV' => 'local',
        'DYNAMODB_ENDPOINT' => 'http://dynamodb:8000',
        'DYNAMODB_TABLE' => 'expense-tracker-test',
        'S3_ENDPOINT' => 'http://s3:9000',
        'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000',
        'RECEIPTS_BUCKET' => 'receipts',
        'THUMBNAILS_BUCKET' => 'receipt-thumbnails',
        'AWS_REGION' => 'eu-west-1',
    ];

    public function testBuildsTheRealReceiptProcessorFromTheEnvironment(): void
    {
        $worker = WorkerFactory::create(self::ENV);

        $processor = (new \ReflectionProperty(SqsBatchProcessor::class, 'processor'))->getValue($worker);
        self::assertInstanceOf(ReceiptProcessor::class, $processor);
    }

    public function testSkipsAnObjectItDoesNotOwnLogsItsDecodedKeyAndReportsMalformedBodiesAsFailures(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $event = ['Records' => [
            ['messageId' => 'good', 'body' => '{"Records":[{"s3":{"bucket":{"name":"receipts"},"object":{"key":"receipts%2Fa"}}}]}'],
            ['messageId' => 'bad', 'body' => 'not json'],
        ]];

        $result = WorkerFactory::create(self::ENV, $stream)->handle($event);

        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'bad']]], $result);
        rewind($stream);
        $output = (string) stream_get_contents($stream);
        self::assertStringContainsString('"message":"receipt event skipped"', $output);
        self::assertStringContainsString('"key":"receipts/a"', $output);
        self::assertStringContainsString('"bucket":"receipts"', $output);
        self::assertStringContainsString('"messageId":"bad"', $output);
    }

    public function testWithoutAStreamItLogsToStderrAndStillBuilds(): void
    {
        $worker = WorkerFactory::create(self::ENV);

        self::assertSame(['batchItemFailures' => []], $worker->handle(['Records' => []]));
    }

    public function testRefusesToStartWithoutTheRequiredSettings(): void
    {
        try {
            WorkerFactory::create([]);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertSame(['DYNAMODB_TABLE is missing', 'RECEIPTS_BUCKET is missing', 'THUMBNAILS_BUCKET is missing'], $e->errors);
        }
    }

    public function testRefusesToStartWithAnEndpointThatIsNotALocalStandIn(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('S3_ENDPOINT points at real AWS');

        WorkerFactory::create(['S3_ENDPOINT' => 'https://s3.eu-west-1.amazonaws.com'] + self::ENV);
    }
}
