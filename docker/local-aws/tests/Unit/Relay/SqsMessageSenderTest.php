<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Relay;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Aws\MockHandler;
use Aws\Exception\AwsException;
use Aws\CommandInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use LocalAws\Relay\MessageSendFailed;
use LocalAws\Relay\RelaySettings;
use LocalAws\Relay\SqsClientFactory;
use LocalAws\Relay\SqsMessageSender;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SqsMessageSenderTest extends TestCase
{
    private const QUEUE_URL = 'http://sqs:9324/000000000000/receipts';

    private static function settings(): RelaySettings
    {
        return RelaySettings::fromEnvironment([
            'AWS_REGION' => 'eu-west-1',
            'AWS_ACCESS_KEY_ID' => 'localdev',
            'AWS_SECRET_ACCESS_KEY' => 'localdevsecret',
            'SQS_ENDPOINT' => 'http://sqs:9324',
            'SQS_RECEIPTS_QUEUE_URL' => self::QUEUE_URL,
            'S3_WEBHOOK_AUTH_TOKEN' => 'localdev-webhook-token',
        ]);
    }

    private static function clientWith(MockHandler $mock): SqsClient
    {
        return new SqsClient(['version' => '2012-11-05', 'region' => 'eu-west-1', 'endpoint' => 'http://sqs:9324', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $mock]);
    }

    public function testSendsTheBodyAsIsToTheConfiguredQueue(): void
    {
        $seen = [];
        $mock = new MockHandler([static function (CommandInterface $command) use (&$seen): Result {
            $seen = $command->toArray();

            return new Result(['MessageId' => 'm-1']);
        }]);

        (new SqsMessageSender(self::clientWith($mock), self::QUEUE_URL))->send('{"Records":[{"a":"%2F"}]}');

        self::assertSame(self::QUEUE_URL, $seen['QueueUrl'] ?? null);
        self::assertSame('{"Records":[{"a":"%2F"}]}', $seen['MessageBody'] ?? null);
        self::assertCount(0, $mock);
    }

    public function testWrapsAnSdkErrorInMessageSendFailedKeepingThePreviousException(): void
    {
        $mock = new MockHandler([static fn(CommandInterface $command): AwsException => new AwsException('queue does not exist', $command)]);
        $sender = new SqsMessageSender(self::clientWith($mock), self::QUEUE_URL);

        try {
            $sender->send('{}');
            self::fail('expected MessageSendFailed');
        } catch (MessageSendFailed $e) {
            self::assertStringContainsString('queue does not exist', $e->getMessage());
            self::assertInstanceOf(AwsException::class, $e->getPrevious());
        }
    }

    public function testFactoryOptionsCarryExplicitShortTimeoutsAndNoSdkRetries(): void
    {
        $options = SqsClientFactory::options(self::settings());

        self::assertSame(['connect_timeout' => 1, 'timeout' => 3], $options['http']);
        self::assertSame(0, $options['retries']);
        self::assertSame('http://sqs:9324', $options['endpoint']);
        self::assertSame('eu-west-1', $options['region']);
        self::assertSame(['key' => 'localdev', 'secret' => 'localdevsecret'], $options['credentials']);
    }

    public function testTheBuiltClientSendsEveryRequestWithTheTimeouts(): void
    {
        $seen = [];
        $handler = static function (RequestInterface $request, array $options) use (&$seen): PromiseInterface {
            $seen = $options;

            return Create::promiseFor(new Response(200, [], '{"MessageId":"m","MD5OfMessageBody":"x"}'));
        };

        $client = SqsClientFactory::create(self::settings(), $handler);
        (new SqsMessageSender($client, self::QUEUE_URL))->send('{}');

        self::assertSame(1, $seen['connect_timeout'] ?? null);
        self::assertSame(3, $seen['timeout'] ?? null);
    }
}
