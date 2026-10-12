<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use LocalAws\Runner\QueueClientFactory;
use LocalAws\Runner\RunnerSettings;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class QueueClientFactoryTest extends TestCase
{
    private static function settings(): RunnerSettings
    {
        return RunnerSettings::fromEnvironment([
            'AWS_REGION' => 'eu-west-1',
            'AWS_ACCESS_KEY_ID' => 'localdev',
            'AWS_SECRET_ACCESS_KEY' => 'localdevsecret',
            'SQS_ENDPOINT' => 'http://sqs:9324',
            'SQS_RECEIPTS_QUEUE_URL' => 'http://sqs:9324/000000000000/receipts',
            'WORKER_INVOCATION_URL' => 'http://worker:8080/2015-03-31/functions/function/invocations',
        ]);
    }

    public function testTheRequestTimeoutOutlastsTheLongPollAndThereAreNoSdkRetries(): void
    {
        $options = QueueClientFactory::options(self::settings());

        self::assertSame(1, $options['http']['connect_timeout']);
        self::assertGreaterThan(\LocalAws\Runner\SqsQueueClient::WAIT_TIME_SECONDS, $options['http']['timeout']);
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

            return Create::promiseFor(new Response(200, [], '{}'));
        };

        QueueClientFactory::create(self::settings(), $handler)->getQueueAttributes(['QueueUrl' => 'http://sqs:9324/000000000000/receipts']);

        self::assertSame(1, $seen['connect_timeout'] ?? null);
        self::assertSame(QueueClientFactory::options(self::settings())['http']['timeout'], $seen['timeout'] ?? null);
    }
}
