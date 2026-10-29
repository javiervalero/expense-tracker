<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\S3;

use App\Infrastructure\S3\S3ClientFactory;
use App\Infrastructure\S3\S3Settings;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use PHPUnit\Framework\TestCase;

final class S3ClientFactoryTest extends TestCase
{
    private const BUCKETS = ['RECEIPTS_BUCKET' => 'expense-receipts', 'THUMBNAILS_BUCKET' => 'expense-thumbnails', 'AWS_REGION' => 'eu-west-1'];

    /** @var array<string, string|false> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        foreach (['AWS_ACCESS_KEY_ID' => 'localdev', 'AWS_SECRET_ACCESS_KEY' => 'localdevsecret', 'AWS_EC2_METADATA_DISABLED' => 'true'] as $name => $value) {
            $this->savedEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    private function localSettings(): S3Settings
    {
        return S3Settings::fromEnvironment(self::BUCKETS + ['S3_ENDPOINT' => 'http://api:9100', 'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000']);
    }

    private function presignedUrl(\Aws\S3\S3Client $client): string
    {
        $command = $client->getCommand('GetObject', ['Bucket' => 'expense-receipts', 'Key' => 'k']);

        return (string) $client->createPresignedRequest($command, '+5 minutes')->getUri();
    }

    public function testTheSigningClientUsesThePublicEndpointInPathStyleLocally(): void
    {
        $client = S3ClientFactory::forSigning($this->localSettings());

        self::assertSame('http://localhost:9000', (string) $client->getEndpoint());
        self::assertTrue($client->getConfig('use_path_style_endpoint'));
        self::assertStringStartsWith('http://localhost:9000/expense-receipts/k?', $this->presignedUrl($client));
    }

    public function testTheCallClientUsesTheInternalEndpointInPathStyleLocally(): void
    {
        $client = S3ClientFactory::forCalls($this->localSettings());

        self::assertSame('http://api:9100', (string) $client->getEndpoint());
        self::assertTrue($client->getConfig('use_path_style_endpoint'));
    }

    public function testOnAwsTheSigningClientMakesVirtualHostedUrlsSoEachBucketIsItsOwnOrigin(): void
    {
        $client = S3ClientFactory::forSigning(S3Settings::fromEnvironment(self::BUCKETS));

        self::assertFalse($client->getConfig('use_path_style_endpoint'));
        self::assertStringStartsWith('https://expense-receipts.s3.eu-west-1.amazonaws.com/k?', $this->presignedUrl($client));
    }

    public function testOnAwsTheCallClientIsVirtualHostedToo(): void
    {
        $client = S3ClientFactory::forCalls(S3Settings::fromEnvironment(self::BUCKETS));

        self::assertFalse($client->getConfig('use_path_style_endpoint'));
        self::assertSame('https://s3.eu-west-1.amazonaws.com', (string) $client->getEndpoint());
    }

    public function testTheRegionIsTheConfiguredOne(): void
    {
        $client = S3ClientFactory::forCalls(S3Settings::fromEnvironment(self::BUCKETS));

        self::assertSame('eu-west-1', $client->getRegion());
    }

    public function testAThrottledCallIsAttemptedThreeTimesInTotal(): void
    {
        $attempts = 0;
        $busy = static function (CommandInterface $command) use (&$attempts): never {
            ++$attempts;

            throw new S3Exception('slow down', $command, ['code' => 'SlowDown', 'response' => null]);
        };
        $client = S3ClientFactory::forCalls(S3Settings::fromEnvironment(self::BUCKETS), new MockHandler([$busy, $busy, $busy, $busy, $busy]));

        try {
            $client->deleteObject(['Bucket' => 'expense-receipts', 'Key' => 'k']);
            self::fail('Expected the error after the retries');
        } catch (S3Exception) {
            self::assertSame(3, $attempts);
        }
    }

    public function testShortConnectTimeoutAndABoundedRequestTimeout(): void
    {
        /** @var array{connect_timeout?: int, timeout?: int} $options */
        $options = [];
        $handler = new MockHandler([static function (CommandInterface $command) use (&$options): Result {
            $http = $command['@http'];
            self::assertIsArray($http);
            $options = $http;

            return new Result([]);
        }]);

        S3ClientFactory::forCalls(S3Settings::fromEnvironment(self::BUCKETS), $handler)->deleteObject(['Bucket' => 'expense-receipts', 'Key' => 'k']);

        self::assertSame(2, $options['connect_timeout'] ?? null);
        self::assertSame(20, $options['timeout'] ?? null);
    }

    public function testAnEndpointFromTheEnvironmentNeverMovesTheClient(): void
    {
        $saved = [getenv('AWS_ENDPOINT_URL'), getenv('AWS_ENDPOINT_URL_S3')];
        putenv('AWS_ENDPOINT_URL=https://evil.example');
        putenv('AWS_ENDPOINT_URL_S3=https://evil-s3.example');
        try {
            $settings = S3Settings::fromEnvironment(self::BUCKETS);

            self::assertSame('https://s3.eu-west-1.amazonaws.com', (string) S3ClientFactory::forCalls($settings)->getEndpoint());
            self::assertSame('https://s3.eu-west-1.amazonaws.com', (string) S3ClientFactory::forSigning($settings)->getEndpoint());
        } finally {
            putenv($saved[0] === false ? 'AWS_ENDPOINT_URL' : 'AWS_ENDPOINT_URL=' . $saved[0]);
            putenv($saved[1] === false ? 'AWS_ENDPOINT_URL_S3' : 'AWS_ENDPOINT_URL_S3=' . $saved[1]);
        }
    }
}
