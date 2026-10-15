<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\DynamoDb;

use App\Infrastructure\Config\InvalidConfiguration;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DynamoDbSettingsTest extends TestCase
{
    public function testTheTableAloneIsEnough(): void
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-prod']);

        self::assertSame('expense-tracker-prod', $settings->tableName);
        self::assertNull($settings->endpoint);
        self::assertNull($settings->region);
    }

    public function testEndpointAndRegionAreReadWhenPresent(): void
    {
        $settings = DynamoDbSettings::fromEnvironment([
            'DYNAMODB_TABLE' => 'expense-tracker-local',
            'DYNAMODB_ENDPOINT' => 'http://dynamodb:8000',
            'AWS_REGION' => 'eu-west-1',
        ]);

        self::assertSame('http://dynamodb:8000', $settings->endpoint);
        self::assertSame('eu-west-1', $settings->region);
    }

    public function testEmptyOptionalValuesCountAsAbsent(): void
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'DYNAMODB_ENDPOINT' => '', 'AWS_REGION' => '']);

        self::assertNull($settings->endpoint);
        self::assertNull($settings->region);
    }

    public function testMissingTableIsReportedByName(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('DYNAMODB_TABLE is missing');

        DynamoDbSettings::fromEnvironment([]);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidTables(): iterable
    {
        yield 'empty' => ['', 'DYNAMODB_TABLE is empty'];
        yield 'whitespace' => ['  ', 'DYNAMODB_TABLE is empty'];
        yield 'not a string' => [12, 'DYNAMODB_TABLE is not a string'];
        yield 'too short' => ['ab', 'DYNAMODB_TABLE is not a valid table name'];
        yield 'too long' => [str_repeat('a', 256), 'DYNAMODB_TABLE is not a valid table name'];
        yield 'illegal character' => ['my table', 'DYNAMODB_TABLE is not a valid table name'];
        yield 'trailing newline' => ["abc\n", 'DYNAMODB_TABLE is not a valid table name'];
    }

    #[DataProvider('invalidTables')]
    public function testInvalidTableIsRejectedWithoutEchoingTheValue(mixed $value, string $expected): void
    {
        try {
            DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => $value]);
            self::fail('Expected InvalidConfiguration');
        } catch (InvalidConfiguration $e) {
            self::assertSame([$expected], $e->errors);
            self::assertStringNotContainsString('my table', $e->getMessage());
        }
    }

    public function testTableNameBoundariesAreAccepted(): void
    {
        self::assertSame('abc', DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'abc'])->tableName);
        $long = str_repeat('a', 255);
        self::assertSame($long, DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => $long])->tableName);
        self::assertSame('A_b-c.9', DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'A_b-c.9'])->tableName);
    }

    public function testNonStringOptionalValuesAreRejected(): void
    {
        try {
            DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'DYNAMODB_ENDPOINT' => 5, 'AWS_REGION' => ['x']]);
            self::fail('Expected InvalidConfiguration');
        } catch (InvalidConfiguration $e) {
            self::assertSame(['AWS_REGION is not a string', 'DYNAMODB_ENDPOINT is not a string'], $e->errors);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedEndpoints(): iterable
    {
        yield 'compose service' => ['http://dynamodb:8000'];
        yield 'localhost' => ['http://localhost:8000'];
        yield 'loopback' => ['http://127.0.0.1:8000'];
        yield 'ipv6 loopback' => ['http://[::1]:8000'];
        yield 'docker host alias' => ['https://host.docker.internal:8000'];
        yield 'upper case scheme and host' => ['HTTP://LOCALHOST:8000'];
    }

    #[DataProvider('acceptedEndpoints')]
    public function testLocalEndpointsAreAccepted(string $endpoint): void
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'DYNAMODB_ENDPOINT' => $endpoint]);

        self::assertSame($endpoint, $settings->endpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedEndpoints(): iterable
    {
        yield 'real aws' => ['https://dynamodb.eu-west-1.amazonaws.com'];
        yield 'real aws china' => ['https://dynamodb.cn-north-1.amazonaws.com.cn'];
        yield 'real aws api.aws' => ['https://dynamodb.eu-west-1.api.aws'];
        yield 'real aws with trailing dot' => ['https://dynamodb.eu-west-1.amazonaws.com.'];
        yield 'other host' => ['http://attacker.example:8000'];
        yield 'dotted name' => ['http://db.internal:8000'];
        yield 'integer ip' => ['http://2130706433'];
        yield 'userinfo hides the host' => ['http://localhost@attacker.example'];
        yield 'userinfo with password' => ['http://user:pw@dynamodb:8000'];
        yield 'wrong scheme' => ['ftp://dynamodb:8000'];
        yield 'no scheme' => ['dynamodb:8000'];
        yield 'no host' => ['http://'];
        yield 'not a url' => ['not a url'];
    }

    #[DataProvider('rejectedEndpoints')]
    public function testNonLocalEndpointsAreRejectedWithoutEchoingTheValue(string $endpoint): void
    {
        try {
            DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'DYNAMODB_ENDPOINT' => $endpoint]);
            self::fail('Expected InvalidConfiguration');
        } catch (InvalidConfiguration $e) {
            self::assertCount(1, $e->errors);
            self::assertStringStartsWith('DYNAMODB_ENDPOINT ', $e->errors[0]);
            self::assertStringNotContainsString('attacker', $e->getMessage());
            self::assertStringNotContainsString('amazonaws', $e->getMessage());
            self::assertStringNotContainsString('pw', $e->getMessage());
            self::assertStringNotContainsString($endpoint, $e->getMessage());
        }
    }

    public function testWhitespaceOnlyEndpointIsInvalidNotAbsent(): void
    {
        $this->expectException(InvalidConfiguration::class);

        DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'DYNAMODB_ENDPOINT' => '  ']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedRegions(): iterable
    {
        yield 'eu-west-1' => ['eu-west-1'];
        yield 'us-east-1' => ['us-east-1'];
        yield 'gov' => ['us-gov-west-1'];
        yield 'three parts' => ['ap-southeast-2'];
    }

    #[DataProvider('acceptedRegions')]
    public function testRealRegionNamesAreAccepted(string $region): void
    {
        self::assertSame($region, DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'AWS_REGION' => $region])->region);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedRegions(): iterable
    {
        yield 'whitespace only' => ['   '];
        yield 'upper case' => ['EU-WEST-1'];
        yield 'no number' => ['eu-west'];
        yield 'host injection' => ['eu-west-1.attacker.example/'];
        yield 'trailing newline' => ["eu-west-1\n"];
        yield 'leading space' => [' eu-west-1'];
        yield 'garbage' => ['nowhere'];
    }

    #[DataProvider('rejectedRegions')]
    public function testInvalidRegionIsRejectedWithoutEchoingTheValue(string $region): void
    {
        try {
            DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'tbl', 'AWS_REGION' => $region]);
            self::fail('Expected InvalidConfiguration');
        } catch (InvalidConfiguration $e) {
            self::assertSame(['AWS_REGION is not a valid region'], $e->errors);
        }
    }
}
