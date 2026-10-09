<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Worker\WorkerFactory;
use PHPUnit\Framework\TestCase;

final class WorkerFactoryTest extends TestCase
{
    public function testBuildsAWorkerThatLogsReceivedObjectsAndReportsMalformedBodiesAsFailures(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $event = ['Records' => [
            ['messageId' => 'good', 'body' => '{"Records":[{"s3":{"bucket":{"name":"receipts"},"object":{"key":"receipts%2Fa"}}}]}'],
            ['messageId' => 'bad', 'body' => 'not json'],
        ]];

        $result = WorkerFactory::create($stream)->handle($event);

        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'bad']]], $result);
        rewind($stream);
        $output = (string) stream_get_contents($stream);
        self::assertStringContainsString('"message":"receipt received"', $output);
        self::assertStringContainsString('"key":"receipts/a"', $output);
        self::assertStringContainsString('"messageId":"bad"', $output);
    }

    public function testWithoutAStreamItLogsToStderrAndStillBuilds(): void
    {
        $worker = WorkerFactory::create();

        self::assertSame(['batchItemFailures' => []], $worker->handle(['Records' => []]));
    }
}
