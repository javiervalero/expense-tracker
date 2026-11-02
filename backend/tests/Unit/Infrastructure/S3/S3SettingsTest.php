<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\S3;

use App\Infrastructure\Config\InvalidConfiguration;
use App\Infrastructure\S3\S3Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class S3SettingsTest extends TestCase
{
    private const BUCKETS = ['RECEIPTS_BUCKET' => 'expense-receipts', 'THUMBNAILS_BUCKET' => 'expense-receipt-thumbnails'];

    public function testTheBucketsAloneAreEnoughOnAws(): void
    {
        $settings = S3Settings::fromEnvironment(self::BUCKETS + ['AWS_REGION' => 'eu-west-1']);

        self::assertSame('expense-receipts', $settings->receiptsBucket);
        self::assertSame('expense-receipt-thumbnails', $settings->thumbnailsBucket);
        self::assertNull($settings->endpoint);
        self::assertNull($settings->publicEndpoint);
        self::assertSame('eu-west-1', $settings->region);
    }

    public function testBothLocalEndpointsAreRead(): void
    {
        $settings = S3Settings::fromEnvironment(self::BUCKETS + ['S3_ENDPOINT' => 'http://api:9100', 'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000']);

        self::assertSame('http://api:9100', $settings->endpoint);
        self::assertSame('http://localhost:9000', $settings->publicEndpoint);
        self::assertNull($settings->region);
    }

    public function testEmptyOptionalValuesCountAsAbsent(): void
    {
        $settings = S3Settings::fromEnvironment(self::BUCKETS + ['S3_ENDPOINT' => '', 'S3_PUBLIC_ENDPOINT' => '', 'AWS_REGION' => '']);

        self::assertNull($settings->endpoint);
        self::assertNull($settings->publicEndpoint);
        self::assertNull($settings->region);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidEnvironments(): iterable
    {
        yield 'receipts bucket missing' => [['THUMBNAILS_BUCKET' => 'thumbs-bucket'], 'RECEIPTS_BUCKET is missing'];
        yield 'thumbnails bucket missing' => [['RECEIPTS_BUCKET' => 'receipts-bucket'], 'THUMBNAILS_BUCKET is missing'];
        yield 'bucket empty' => [['RECEIPTS_BUCKET' => '', 'THUMBNAILS_BUCKET' => 'thumbs-bucket'], 'RECEIPTS_BUCKET is empty'];
        yield 'bucket not a string' => [['RECEIPTS_BUCKET' => 5, 'THUMBNAILS_BUCKET' => 'thumbs-bucket'], 'RECEIPTS_BUCKET is not a string'];
        yield 'bucket upper case' => [['RECEIPTS_BUCKET' => 'Receipts-Bucket', 'THUMBNAILS_BUCKET' => 'thumbs-bucket'], 'RECEIPTS_BUCKET is not a valid bucket name'];
        yield 'bucket too short' => [['RECEIPTS_BUCKET' => 'ab', 'THUMBNAILS_BUCKET' => 'thumbs-bucket'], 'RECEIPTS_BUCKET is not a valid bucket name'];
        yield 'bucket with a dot' => [['RECEIPTS_BUCKET' => 'my.receipts', 'THUMBNAILS_BUCKET' => 'thumbs-bucket'], 'RECEIPTS_BUCKET is not a valid bucket name'];
        yield 'bucket ends with a hyphen' => [['RECEIPTS_BUCKET' => 'receipts-', 'THUMBNAILS_BUCKET' => 'thumbs-bucket'], 'RECEIPTS_BUCKET is not a valid bucket name'];
        yield 'same bucket twice' => [['RECEIPTS_BUCKET' => 'one-bucket', 'THUMBNAILS_BUCKET' => 'one-bucket'], 'THUMBNAILS_BUCKET must differ from RECEIPTS_BUCKET'];
        yield 'real AWS endpoint' => [self::BUCKETS + ['S3_ENDPOINT' => 'https://s3.eu-west-1.amazonaws.com', 'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000'], 'S3_ENDPOINT points at real AWS'];
        yield 'public endpoint not local' => [self::BUCKETS + ['S3_ENDPOINT' => 'http://api:9100', 'S3_PUBLIC_ENDPOINT' => 'https://example.com'], 'S3_PUBLIC_ENDPOINT is not a local stand-in (localhost, loopback, host.docker.internal or a Compose service name)'];
        yield 'endpoint not a string' => [self::BUCKETS + ['S3_ENDPOINT' => 7, 'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000'], 'S3_ENDPOINT is not a string'];
        yield 'endpoint without the public one' => [self::BUCKETS + ['S3_ENDPOINT' => 'http://api:9100'], 'S3_PUBLIC_ENDPOINT is required when S3_ENDPOINT is set'];
        yield 'public endpoint without the other' => [self::BUCKETS + ['S3_PUBLIC_ENDPOINT' => 'http://localhost:9000'], 'S3_ENDPOINT is required when S3_PUBLIC_ENDPOINT is set'];
        yield 'bad region' => [self::BUCKETS + ['AWS_REGION' => 'mars'], 'AWS_REGION is not a valid region'];
    }

    /**
     * @param array<string, mixed> $env
     */
    #[DataProvider('invalidEnvironments')]
    public function testAnInvalidEnvironmentIsReportedByVariableName(array $env, string $expected): void
    {
        try {
            S3Settings::fromEnvironment($env);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertSame([$expected], $e->errors);
        }
    }

    public function testNoErrorEverEchoesAValue(): void
    {
        $env = ['RECEIPTS_BUCKET' => 'Hunter2-Bucket', 'THUMBNAILS_BUCKET' => 'hunter2-bucket', 'S3_ENDPOINT' => 'https://user:hunter2@s3.amazonaws.com', 'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000', 'AWS_REGION' => 'hunter2'];

        try {
            S3Settings::fromEnvironment($env);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertGreaterThanOrEqual(3, count($e->errors));
            self::assertStringNotContainsStringIgnoringCase('hunter2', $e->getMessage());
        }
    }

    public function testErrorsAreSorted(): void
    {
        try {
            S3Settings::fromEnvironment(['AWS_REGION' => 'mars']);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            $sorted = $e->errors;
            sort($sorted);
            self::assertSame($sorted, $e->errors);
            self::assertCount(3, $e->errors);
        }
    }
}
