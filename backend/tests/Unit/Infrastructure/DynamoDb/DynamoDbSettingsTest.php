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
}
