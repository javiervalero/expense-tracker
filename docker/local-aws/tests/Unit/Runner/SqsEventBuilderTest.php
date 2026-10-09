<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use LocalAws\Runner\QueueMessage;
use LocalAws\Runner\SqsEventBuilder;
use PHPUnit\Framework\TestCase;

final class SqsEventBuilderTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/sqs-events/aws-lambda-sqs-event.json';
    private const ARN = 'arn:aws:sqs:us-east-2:123456789012:my-queue';

    /**
     * The two messages of the AWS documentation example, as the SDK's ReceiveMessage would return them.
     *
     * @return list<QueueMessage>
     */
    private static function documentationMessages(): array
    {
        return [
            new QueueMessage(
                '059f36b4-87a3-44ab-83d2-661975830a7d',
                'AQEBwJnKyrHigUMZj6rYigCgxlaS3SLy0a...',
                'Test message.',
                'e4e68fb7bd0e697a0ae8f1bb342846b3',
                [
                    'ApproximateReceiveCount' => '1',
                    'SentTimestamp' => '1545082649183',
                    'SenderId' => 'AIDAIENQZJOLO23YVJ4VO',
                    'ApproximateFirstReceiveTimestamp' => '1545082649185',
                ],
                ['myAttribute' => ['dataType' => 'String', 'stringValue' => 'myValue', 'binaryValue' => null]],
            ),
            new QueueMessage(
                '2e1424d4-f796-459a-8184-9c92662be6da',
                'AQEBzWwaftRI0KuVm4tP+/7q1rGgNqicHq...',
                'Test message.',
                'e4e68fb7bd0e697a0ae8f1bb342846b3',
                [
                    'ApproximateReceiveCount' => '1',
                    'SentTimestamp' => '1545082650636',
                    'SenderId' => 'AIDAIENQZJOLO23YVJ4VO',
                    'ApproximateFirstReceiveTimestamp' => '1545082650649',
                ],
                [],
            ),
        ];
    }

    public function testBuildsExactlyTheEventOfTheAwsDocumentation(): void
    {
        $fixture = file_get_contents(self::FIXTURE);
        self::assertIsString($fixture);

        $json = (new SqsEventBuilder('us-east-2', self::ARN))->build(self::documentationMessages());

        self::assertSame(json_decode($fixture, true, 512, JSON_THROW_ON_ERROR), json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testNoMessageAttributesIsAJsonObjectNotAnArray(): void
    {
        $json = (new SqsEventBuilder('us-east-2', self::ARN))->build([self::documentationMessages()[1]]);

        self::assertStringContainsString('"messageAttributes":{}', $json);
    }

    public function testBinaryAttributesAreBase64AndTheBodyKeepsItsBytes(): void
    {
        $message = new QueueMessage('m-1', 'h-1', "{\"key\":\"a/ü😀\"}", 'md5', [], [
            'blob' => ['dataType' => 'Binary', 'stringValue' => null, 'binaryValue' => "\x00\xff"],
        ]);

        $json = (new SqsEventBuilder('eu-west-1', self::ARN))->build([$message]);

        $record = Decoded::records($json)[0];
        self::assertSame("{\"key\":\"a/ü😀\"}", $record['body']);
        self::assertSame('eu-west-1', $record['awsRegion']);
        self::assertSame(
            ['blob' => ['binaryValue' => base64_encode("\x00\xff"), 'stringListValues' => [], 'binaryListValues' => [], 'dataType' => 'Binary']],
            $record['messageAttributes'],
        );
        self::assertStringContainsString('"attributes":{}', $json);
    }

    public function testKeepsTheOrderOfTheMessages(): void
    {
        $messages = array_reverse(self::documentationMessages());

        $json = (new SqsEventBuilder('us-east-2', self::ARN))->build($messages);

        $ids = array_column(Decoded::records($json), 'messageId');
        self::assertSame(['2e1424d4-f796-459a-8184-9c92662be6da', '059f36b4-87a3-44ab-83d2-661975830a7d'], $ids);
    }
}
