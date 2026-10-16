<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Bootstrap;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use LocalAws\Bootstrap\BootstrapSettings;
use LocalAws\Bootstrap\DynamoDbClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class DynamoDbClientFactoryTest extends TestCase
{
    private static function settings(): BootstrapSettings
    {
        return BootstrapSettings::fromEnvironment([
            'AWS_REGION' => 'eu-west-1',
            'AWS_ACCESS_KEY_ID' => 'localdev',
            'AWS_SECRET_ACCESS_KEY' => 'localdevsecret',
            'S3_ENDPOINT' => 'http://s3:9000',
            'S3_CORS_ALLOWED_ORIGIN' => 'http://localhost:5173',
            'DYNAMODB_ENDPOINT' => 'http://dynamodb:8000',
            'DYNAMODB_TABLE' => 'expense-tracker-local',
        ]);
    }

    public function testOptionsCarryExplicitHttpTimeoutsAndASingleRetry(): void
    {
        $options = DynamoDbClientFactory::options(self::settings());

        self::assertSame(['connect_timeout' => 3, 'timeout' => 10], $options['http']);
        self::assertSame(1, $options['retries']);
    }

    public function testOptionsPointAtTheConfiguredEndpointWithExplicitCredentials(): void
    {
        $options = DynamoDbClientFactory::options(self::settings());

        self::assertSame('http://dynamodb:8000', $options['endpoint']);
        self::assertSame('eu-west-1', $options['region']);
        self::assertSame(['key' => 'localdev', 'secret' => 'localdevsecret'], $options['credentials']);
    }

    public function testTheBuiltClientSendsEveryRequestWithTheTimeouts(): void
    {
        $seen = [];
        $handler = static function (RequestInterface $request, array $options) use (&$seen): PromiseInterface {
            $seen = $options;

            return Create::promiseFor(new Response(200, [], '{"TableNames":[]}'));
        };

        DynamoDbClientFactory::create(self::settings(), $handler)->listTables();

        self::assertSame(3, $seen['connect_timeout'] ?? null);
        self::assertSame(10, $seen['timeout'] ?? null);
    }
}
