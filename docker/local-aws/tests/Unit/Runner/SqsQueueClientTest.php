<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use LocalAws\Runner\SqsQueueClient;
use PHPUnit\Framework\TestCase;

final class SqsQueueClientTest extends TestCase
{
    private const QUEUE_URL = 'http://sqs:9324/000000000000/receipts';

    private static function clientWith(MockHandler $mock): SqsClient
    {
        return new SqsClient(['version' => '2012-11-05', 'region' => 'eu-west-1', 'endpoint' => 'http://sqs:9324', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $mock]);
    }

    /**
     * The MD5 of the message attributes, by the algorithm of the SQS documentation (the SDK checks it): for each
     * attribute sorted by name, length-prefixed name, data type and value, with a transport byte (1 string, 2 binary).
     */
    private static function attributesMd5(): string
    {
        $part = static fn(string $value): string => pack('N', strlen($value)) . $value;
        $kind = $part('kind') . $part('String') . "\x01" . $part('receipt');
        $blob = $part('blob') . $part('Binary') . "\x02" . $part("\x00\x01");

        return md5($blob . $kind);
    }

    public function testLongPollsTenMessagesWithAllAttributesAndMapsTheAnswer(): void
    {
        $seen = [];
        $mock = new MockHandler([static function (CommandInterface $command) use (&$seen): Result {
            $seen = $command->toArray();

            return new Result(['Messages' => [
                [
                    'MessageId' => 'm-1',
                    'ReceiptHandle' => 'h-1',
                    'Body' => 'body 1',
                    'MD5OfBody' => md5('body 1'),
                    'MD5OfMessageAttributes' => self::attributesMd5(),
                    'Attributes' => ['SentTimestamp' => '1545082649183', 'ApproximateReceiveCount' => '2'],
                    'MessageAttributes' => [
                        'kind' => ['DataType' => 'String', 'StringValue' => 'receipt'],
                        'blob' => ['DataType' => 'Binary', 'BinaryValue' => "\x00\x01"],
                    ],
                ],
                ['MessageId' => 'm-2', 'ReceiptHandle' => 'h-2', 'Body' => 'body 2', 'MD5OfBody' => md5('body 2')],
            ]]);
        }]);

        $messages = (new SqsQueueClient(self::clientWith($mock), self::QUEUE_URL))->receive();

        self::assertSame(self::QUEUE_URL, $seen['QueueUrl'] ?? null);
        self::assertSame(10, $seen['MaxNumberOfMessages'] ?? null);
        self::assertSame(20, $seen['WaitTimeSeconds'] ?? null);
        self::assertSame(['All'], $seen['AttributeNames'] ?? null);
        self::assertSame(['All'], $seen['MessageAttributeNames'] ?? null);
        self::assertCount(2, $messages);
        self::assertSame('m-1', $messages[0]->messageId);
        self::assertSame('h-1', $messages[0]->receiptHandle);
        self::assertSame('body 1', $messages[0]->body);
        self::assertSame(md5('body 1'), $messages[0]->md5OfBody);
        self::assertSame(['SentTimestamp' => '1545082649183', 'ApproximateReceiveCount' => '2'], $messages[0]->attributes);
        self::assertSame([
            'kind' => ['dataType' => 'String', 'stringValue' => 'receipt', 'binaryValue' => null],
            'blob' => ['dataType' => 'Binary', 'stringValue' => null, 'binaryValue' => "\x00\x01"],
        ], $messages[0]->messageAttributes);
        self::assertSame([], $messages[1]->attributes);
        self::assertSame([], $messages[1]->messageAttributes);
    }

    public function testAnEmptyPollReturnsNoMessages(): void
    {
        $mock = new MockHandler([new Result([])]);

        self::assertSame([], (new SqsQueueClient(self::clientWith($mock), self::QUEUE_URL))->receive());
    }

    public function testRefusesAMessageWithoutTheFieldsTheEventNeeds(): void
    {
        $mock = new MockHandler([new Result(['Messages' => [['MessageId' => 'm-1', 'Body' => 'b', 'MD5OfBody' => md5('b')]]])]);
        $client = new SqsQueueClient(self::clientWith($mock), self::QUEUE_URL);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('ReceiptHandle');

        $client->receive();
    }

    public function testDeletesInOneBatchAndReturnsTheMessageIdsThatFailed(): void
    {
        $seen = [];
        $mock = new MockHandler([static function (CommandInterface $command) use (&$seen): Result {
            $seen = $command->toArray();

            return new Result([
                'Successful' => [['Id' => '0']],
                'Failed' => [['Id' => '1', 'Code' => 'ReceiptHandleIsInvalid', 'SenderFault' => true]],
            ]);
        }]);
        $messages = [
            new \LocalAws\Runner\QueueMessage('m-1', 'h-1', 'b', 'x', [], []),
            new \LocalAws\Runner\QueueMessage('m-2', 'h-2', 'b', 'x', [], []),
        ];

        $failed = (new SqsQueueClient(self::clientWith($mock), self::QUEUE_URL))->delete($messages);

        self::assertSame(['m-2'], $failed);
        self::assertSame(self::QUEUE_URL, $seen['QueueUrl'] ?? null);
        self::assertSame([['Id' => '0', 'ReceiptHandle' => 'h-1'], ['Id' => '1', 'ReceiptHandle' => 'h-2']], $seen['Entries'] ?? null);
    }

    public function testDeleteWithNothingToDeleteDoesNotCallTheQueue(): void
    {
        $mock = new MockHandler([]);

        self::assertSame([], (new SqsQueueClient(self::clientWith($mock), self::QUEUE_URL))->delete([]));
        self::assertCount(0, $mock);
    }

    public function testDeleteReportsEveryMessageWhenTheAnswerNamesAnUnknownId(): void
    {
        $mock = new MockHandler([new Result(['Failed' => [['Id' => 'weird', 'Code' => 'X']]])]);
        $message = new \LocalAws\Runner\QueueMessage('m-1', 'h-1', 'b', 'x', [], []);

        $failed = (new SqsQueueClient(self::clientWith($mock), self::QUEUE_URL))->delete([$message]);

        self::assertSame(['m-1'], $failed, 'an answer that cannot be matched is treated as not deleted');
    }

    public function testAnSdkErrorPropagates(): void
    {
        $mock = new MockHandler([static fn(CommandInterface $command): AwsException => new AwsException('queue gone', $command)]);
        $client = new SqsQueueClient(self::clientWith($mock), self::QUEUE_URL);

        $this->expectException(AwsException::class);

        $client->receive();
    }
}
