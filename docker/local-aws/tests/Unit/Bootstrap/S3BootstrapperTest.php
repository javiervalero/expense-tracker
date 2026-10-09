<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Bootstrap;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use LocalAws\Bootstrap\S3Bootstrapper;
use LocalAws\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;

final class S3BootstrapperTest extends TestCase
{
    private const ORIGIN = 'http://localhost:5173';
    private const ARN = 'arn:minio:sqs::RELAY:webhook';

    private MockHandler $mock;
    /** @var list<string> "CommandName Bucket" in the order the SDK sent them */
    private array $calls = [];
    /** @var array<string, array<array-key, mixed>> command name => parameters of the last such command */
    private array $lastParams = [];
    /** @var list<array<string, mixed>> */
    private array $logged = [];

    private function s3(): S3Client
    {
        $this->mock = new MockHandler();
        $this->calls = [];
        $this->lastParams = [];
        $this->logged = [];

        $client = new S3Client([
            'version' => '2006-03-01',
            'region' => 'eu-west-1',
            'endpoint' => 'http://s3:9000',
            'use_path_style_endpoint' => true,
            'retries' => 0,
            'credentials' => ['key' => 'localdev', 'secret' => 'localdevsecret'],
            'handler' => $this->mock,
        ]);
        $client->getHandlerList()->appendSign(\Aws\Middleware::tap(function (CommandInterface $command): void {
            $bucket = is_string($command['Bucket'] ?? null) ? $command['Bucket'] : '-';
            $this->calls[] = $command->getName() . ' ' . $bucket;
            $this->lastParams[$command->getName()] = $command->toArray();
        }));

        return $client;
    }

    private function bootstrapper(S3Client $client, ?string $arn = self::ARN): S3Bootstrapper
    {
        return new S3Bootstrapper(
            $client,
            new JsonLogger(
                function (string $line): void {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    self::assertIsArray($decoded);
                    /** @var array<string, mixed> $decoded */
                    $this->logged[] = $decoded;
                },
                static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-10-09T12:00:00+00:00'),
            ),
            self::ORIGIN,
            $arn,
        );
    }

    private static function missing(string $operation, string $code, int $status): S3Exception
    {
        return new S3Exception(
            $code,
            new \Aws\Command($operation),
            ['code' => $code, 'response' => new Response($status)],
        );
    }

    /**
     * @return array{CORSRules: list<array<string, mixed>>} what GetBucketCors returns for the rule we want
     */
    private static function corsResult(string $origin = self::ORIGIN): array
    {
        return ['CORSRules' => [[
            'AllowedOrigins' => [$origin],
            'AllowedMethods' => ['HEAD', 'GET', 'POST'],
            'AllowedHeaders' => ['*'],
            'ExposeHeaders' => ['ETag'],
            'MaxAgeSeconds' => 600,
        ]]];
    }

    /**
     * @return array{QueueConfigurations: list<array<string, mixed>>} what GetBucketNotificationConfiguration returns for the rule we want
     */
    private static function notificationResult(string $prefix = 'receipts/', string $arn = self::ARN): array
    {
        return ['QueueConfigurations' => [[
            'Id' => 'whatever-the-emulator-says',
            'QueueArn' => $arn,
            'Events' => ['s3:ObjectCreated:*'],
            'Filter' => ['Key' => ['FilterRules' => [['Name' => 'prefix', 'Value' => $prefix]]]],
        ]]];
    }

    /**
     * @param list<array<string, mixed>|\Throwable> $responses
     */
    private function queue(array $responses): void
    {
        foreach ($responses as $response) {
            $this->mock->append($response instanceof \Throwable ? $response : new Result($response));
        }
    }

    /**
     * @return list<string>
     */
    private function writes(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn(string $call): bool => preg_match('/^(Create|Put)/', $call) === 1,
        ));
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(static fn(array $entry): string => is_string($entry['message']) ? $entry['message'] : '', $this->logged);
    }

    public function testCreatesTheMissingBucketsAndConfiguresReceipts(): void
    {
        $client = $this->s3();
        $this->queue([
            self::missing('HeadBucket', 'NotFound', 404), [],
            self::missing('HeadBucket', 'NotFound', 404), [],
            self::missing('GetBucketCors', 'NoSuchCORSConfiguration', 404), [],
            [], [],
        ]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(4, $changes);
        self::assertSame([
            'HeadBucket receipts', 'CreateBucket receipts',
            'HeadBucket receipt-thumbnails', 'CreateBucket receipt-thumbnails',
            'GetBucketCors receipts', 'PutBucketCors receipts',
            'GetBucketNotificationConfiguration receipts', 'PutBucketNotificationConfiguration receipts',
        ], $this->calls);
        self::assertSame(['created bucket', 'created bucket', 'set bucket CORS', 'set bucket notification', 'bootstrap finished'], $this->messages());
    }

    public function testDoesNotCreateBucketsThatAlreadyExist(): void
    {
        $client = $this->s3();
        $this->queue([[], [], self::corsResult(), self::notificationResult()]);

        $this->bootstrapper($client)->run();

        self::assertNotContains('CreateBucket receipts', $this->calls);
        self::assertNotContains('CreateBucket receipt-thumbnails', $this->calls);
    }

    public function testWritesNothingAndSaysSoWhenEverythingAlreadyMatches(): void
    {
        $client = $this->s3();
        $this->queue([[], [], self::corsResult(), self::notificationResult()]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(0, $changes);
        self::assertSame([], $this->writes());
        self::assertSame(['no changes'], $this->messages());
    }

    public function testComparesCorsIgnoringTheOrderOfListsAndExtraFieldsFromTheEmulator(): void
    {
        $client = $this->s3();
        $cors = self::corsResult();
        $cors['CORSRules'][0]['AllowedMethods'] = ['GET', 'POST', 'HEAD'];
        $cors['CORSRules'][0]['ID'] = 'generated-by-emulator';
        $this->queue([[], [], $cors, self::notificationResult()]);

        $this->bootstrapper($client)->run();

        self::assertSame([], $this->writes());
    }

    public function testPutsCorsForTheViteOriginWithPostGetAndHead(): void
    {
        $client = $this->s3();
        $this->queue([[], [], self::missing('GetBucketCors', 'NoSuchCORSConfiguration', 404), [], self::notificationResult()]);

        $this->bootstrapper($client)->run();

        $params = $this->lastParams['PutBucketCors'];
        self::assertSame('receipts', $params['Bucket']);
        self::assertSame(['CORSRules' => [[
            'AllowedOrigins' => [self::ORIGIN],
            'AllowedMethods' => ['POST', 'GET', 'HEAD'],
            'AllowedHeaders' => ['*'],
            'ExposeHeaders' => ['ETag'],
            'MaxAgeSeconds' => 600,
        ]]], $params['CORSConfiguration']);
    }

    public function testReplacesCorsThatAllowsAnotherOrigin(): void
    {
        $client = $this->s3();
        $this->queue([[], [], self::corsResult('http://evil.example'), [], self::notificationResult()]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(1, $changes);
        self::assertSame(['PutBucketCors receipts'], $this->writes());
    }

    public function testPutsTheNotificationRuleOnReceiptsOnlyWithTheCreatedEventsAndPrefix(): void
    {
        $client = $this->s3();
        $this->queue([[], [], self::corsResult(), [], []]);

        $this->bootstrapper($client)->run();

        $params = $this->lastParams['PutBucketNotificationConfiguration'];
        self::assertSame('receipts', $params['Bucket']);
        $configuration = $params['NotificationConfiguration'];
        self::assertIsArray($configuration);
        self::assertSame([[
            'Id' => 'receipts-object-created',
            'QueueArn' => self::ARN,
            'Events' => ['s3:ObjectCreated:*'],
            'Filter' => ['Key' => ['FilterRules' => [['Name' => 'prefix', 'Value' => 'receipts/']]]],
        ]], $configuration['QueueConfigurations']);
    }

    public function testNeverTouchesTheNotificationsOfTheThumbnailsBucket(): void
    {
        $client = $this->s3();
        $this->queue([
            self::missing('HeadBucket', 'NotFound', 404), [],
            self::missing('HeadBucket', 'NotFound', 404), [],
            self::missing('GetBucketCors', 'NoSuchCORSConfiguration', 404), [],
            [], [],
        ]);

        $this->bootstrapper($client)->run();

        foreach ($this->calls as $call) {
            if (str_contains($call, 'Notification') || str_contains($call, 'Cors')) {
                self::assertStringEndsWith(' receipts', $call);
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driftedNotifications')]
    public function testReplacesANotificationRuleThatDiffers(string $prefix, string $arn): void
    {
        $client = $this->s3();
        $this->queue([[], [], self::corsResult(), self::notificationResult($prefix, $arn), []]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(1, $changes);
        self::assertSame(['PutBucketNotificationConfiguration receipts'], $this->writes());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function driftedNotifications(): iterable
    {
        yield 'other prefix' => ['other/', self::ARN];
        yield 'other target' => ['receipts/', 'arn:minio:sqs::OTHER:webhook'];
    }

    public function testReplacesANotificationRuleWithNoFilter(): void
    {
        $client = $this->s3();
        $unfiltered = self::notificationResult();
        unset($unfiltered['QueueConfigurations'][0]['Filter']);
        $this->queue([[], [], self::corsResult(), $unfiltered, []]);

        $this->bootstrapper($client)->run();

        self::assertSame(['PutBucketNotificationConfiguration receipts'], $this->writes());
    }

    public function testSkipsTheNotificationRuleWhenNoWebhookIsConfigured(): void
    {
        $client = $this->s3();
        $this->queue([[], [], self::corsResult()]);

        $changes = $this->bootstrapper($client, null)->run();

        self::assertSame(0, $changes);
        self::assertSame(['HeadBucket receipts', 'HeadBucket receipt-thumbnails', 'GetBucketCors receipts'], $this->calls);
        self::assertSame(
            ['notification rule not managed: no webhook target configured (S3_NOTIFICATION_ARN is empty)', 'no changes'],
            $this->messages(),
        );
    }

    public function testSecondRunAgainstAStatefulFakeMakesNoWrites(): void
    {
        /** @var array{buckets: list<string>, cors: list<array<string, mixed>>|null, notification: array<string, mixed>|null} $state */
        $state = ['buckets' => [], 'cors' => null, 'notification' => null];
        $client = $this->s3();
        $client->getHandlerList()->setHandler(static function (CommandInterface $command) use (&$state): \GuzzleHttp\Promise\PromiseInterface {
            $bucket = is_string($command['Bucket'] ?? null) ? $command['Bucket'] : '';
            $fail = static fn(string $code): \GuzzleHttp\Promise\PromiseInterface => \GuzzleHttp\Promise\Create::rejectionFor(
                new S3Exception($code, $command, ['code' => $code, 'response' => new Response(404)]),
            );
            switch ($command->getName()) {
                case 'HeadBucket':
                    return in_array($bucket, $state['buckets'], true) ? \GuzzleHttp\Promise\Create::promiseFor(new Result([])) : $fail('NotFound');
                case 'CreateBucket':
                    $state['buckets'][] = $bucket;
                    break;
                case 'GetBucketCors':
                    return $state['cors'] === null ? $fail('NoSuchCORSConfiguration') : \GuzzleHttp\Promise\Create::promiseFor(new Result(['CORSRules' => $state['cors']]));
                case 'PutBucketCors':
                    $configuration = $command['CORSConfiguration'];
                    \assert(is_array($configuration) && is_array($configuration['CORSRules']));
                    /** @var list<array<string, mixed>> $rules */
                    $rules = $configuration['CORSRules'];
                    $state['cors'] = $rules;
                    break;
                case 'GetBucketNotificationConfiguration':
                    return \GuzzleHttp\Promise\Create::promiseFor(new Result($state['notification'] ?? []));
                case 'PutBucketNotificationConfiguration':
                    $configuration = $command['NotificationConfiguration'];
                    \assert(is_array($configuration));
                    /** @var array<string, mixed> $configuration */
                    $state['notification'] = $configuration;
                    break;
            }

            return \GuzzleHttp\Promise\Create::promiseFor(new Result([]));
        });
        $bootstrapper = $this->bootstrapper($client);

        $first = $bootstrapper->run();
        $this->calls = [];
        $second = $bootstrapper->run();

        self::assertSame(4, $first);
        self::assertSame(0, $second);
        self::assertSame([], $this->writes());
        self::assertSame(['no changes'], array_slice($this->messages(), -1));
    }

    public function testToleratesTheBucketAppearingBetweenTheCheckAndTheCreate(): void
    {
        $client = $this->s3();
        $this->queue([
            self::missing('HeadBucket', 'NotFound', 404),
            self::missing('CreateBucket', 'BucketAlreadyOwnedByYou', 409),
            [],
            self::corsResult(), self::notificationResult(),
        ]);

        $changes = $this->bootstrapper($client)->run();

        self::assertSame(0, $changes, 'someone else created it, so this run changed nothing');
    }

    public function testFailsOnAnUnexpectedBucketCreationError(): void
    {
        $client = $this->s3();
        $this->queue([
            self::missing('HeadBucket', 'NotFound', 404),
            self::missing('CreateBucket', 'InternalError', 500),
        ]);

        $this->expectException(AwsException::class);

        $this->bootstrapper($client)->run();
    }

    public function testFailsWhenABucketExistsButIsForbidden(): void
    {
        $client = $this->s3();
        $this->queue([self::missing('HeadBucket', 'Forbidden', 403)]);

        $this->expectException(AwsException::class);

        try {
            $this->bootstrapper($client)->run();
        } finally {
            self::assertSame([], $this->writes(), 'a 403 is not "missing": nothing may be created');
        }
    }

    public function testFailsWhenCorsCannotBeRead(): void
    {
        $client = $this->s3();
        $this->queue([[], [], self::missing('GetBucketCors', 'AccessDenied', 403)]);

        $this->expectException(AwsException::class);

        $this->bootstrapper($client)->run();
    }
}
