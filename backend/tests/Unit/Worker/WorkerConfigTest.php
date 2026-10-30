<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Infrastructure\Config\InvalidConfiguration;
use App\Worker\WorkerConfig;
use PHPUnit\Framework\TestCase;

final class WorkerConfigTest extends TestCase
{
    private const VALID = [
        'DYNAMODB_TABLE' => 'expense-tracker-test',
        'RECEIPTS_BUCKET' => 'test-receipts',
        'THUMBNAILS_BUCKET' => 'test-thumbnails',
    ];
    private const LOCAL = [
        'APP_ENV' => 'local',
        'DYNAMODB_ENDPOINT' => 'http://dynamodb:8000',
        'S3_ENDPOINT' => 'http://s3:9000',
        'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000',
        'AWS_REGION' => 'eu-west-1',
    ];

    public function testReadsTheTableTheBucketsTheEndpointsAndTheRegion(): void
    {
        $config = WorkerConfig::fromEnvironment(self::VALID + self::LOCAL);

        self::assertSame('expense-tracker-test', $config->dynamoDb->tableName);
        self::assertSame('http://dynamodb:8000', $config->dynamoDb->endpoint);
        self::assertSame('eu-west-1', $config->dynamoDb->region);
        self::assertSame('test-receipts', $config->s3->receiptsBucket);
        self::assertSame('test-thumbnails', $config->s3->thumbnailsBucket);
        self::assertSame('http://s3:9000', $config->s3->endpoint);
    }

    public function testOnAwsNoEndpointIsNeeded(): void
    {
        $config = WorkerConfig::fromEnvironment(self::VALID);

        self::assertNull($config->dynamoDb->endpoint);
        self::assertNull($config->s3->endpoint);
    }

    public function testMissingRequiredValuesAreAllReportedByNameAndSorted(): void
    {
        try {
            WorkerConfig::fromEnvironment([]);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertSame(['DYNAMODB_TABLE is missing', 'RECEIPTS_BUCKET is missing', 'THUMBNAILS_BUCKET is missing'], $e->errors);
        }
    }

    public function testARealAwsEndpointIsRefusedByNameWithoutEchoingIt(): void
    {
        $env = self::VALID + [
            'APP_ENV' => 'local',
            'DYNAMODB_ENDPOINT' => 'https://dynamodb.eu-west-1.amazonaws.com',
            'S3_ENDPOINT' => 'https://s3.eu-west-1.amazonaws.com',
            'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000',
        ];

        try {
            WorkerConfig::fromEnvironment($env);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertSame(['DYNAMODB_ENDPOINT points at real AWS', 'S3_ENDPOINT points at real AWS'], $e->errors);
            self::assertStringNotContainsString('amazonaws', $e->getMessage());
        }
    }

    public function testALocalEnvironmentWithoutEndpointsFailsClosed(): void
    {
        try {
            WorkerConfig::fromEnvironment(self::VALID + ['APP_ENV' => 'local']);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertSame([
                'DYNAMODB_ENDPOINT is required when APP_ENV is local',
                'S3_ENDPOINT is required when APP_ENV is local',
                'S3_PUBLIC_ENDPOINT is required when APP_ENV is local',
            ], $e->errors);
        }
    }

    public function testAnyEndpointOutsideALocalEnvironmentIsAConfigurationError(): void
    {
        foreach ([[], ['APP_ENV' => 'production']] as $appEnv) {
            try {
                WorkerConfig::fromEnvironment(self::VALID + $appEnv + ['DYNAMODB_ENDPOINT' => 'http://dynamodb:8000', 'S3_ENDPOINT' => 'http://s3:9000', 'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000']);
                self::fail('Expected InvalidConfiguration.');
            } catch (InvalidConfiguration $e) {
                self::assertSame([
                    'DYNAMODB_ENDPOINT is set but APP_ENV is not local',
                    'S3_ENDPOINT is set but APP_ENV is not local',
                    'S3_PUBLIC_ENDPOINT is set but APP_ENV is not local',
                ], $e->errors);
            }
        }
    }

    public function testABadRegionIsReportedOnceAndNeverEchoed(): void
    {
        try {
            WorkerConfig::fromEnvironment(self::VALID + ['AWS_REGION' => 'mars-central-hunter2']);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertCount(1, $e->errors, 'the region is read by two settings classes but reported once');
            self::assertStringStartsWith('AWS_REGION ', $e->errors[0]);
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }
}
