<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Config;

use App\Infrastructure\Config\ApiConfig;
use App\Infrastructure\Config\InvalidConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiConfigTest extends TestCase
{
    private const SLOT_A = 'slot-a-0123456789-0123456789-abcdef';
    private const SLOT_B = 'slot-b-0123456789-0123456789-abcdef';
    private const HEADER = 'CloudFront-Viewer-Address';
    private const TABLE = 'expense-tracker-test';
    private const DYNAMODB = ['DYNAMODB_TABLE' => self::TABLE];

    public function testOneSlotIsAccepted(): void
    {
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A, 'CLIENT_IP_HEADER' => self::HEADER] + self::DYNAMODB);

        self::assertSame([self::SLOT_A], $config->originVerifySecrets);
    }

    public function testTwoSlotsAreAcceptedInOrder(): void
    {
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A . ',' . self::SLOT_B, 'CLIENT_IP_HEADER' => self::HEADER] + self::DYNAMODB);

        self::assertSame([self::SLOT_A, self::SLOT_B], $config->originVerifySecrets);
    }

    public function testASlotOfExactlyThirtyTwoVisibleCharactersIsAccepted(): void
    {
        $slot = str_repeat('~', 32);

        self::assertSame([$slot], ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => $slot, 'CLIENT_IP_HEADER' => self::HEADER] + self::DYNAMODB)->originVerifySecrets);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'missing key is handled separately' => [null];
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \t \n"];
        yield 'not a string' => [12];
        yield 'too short' => [str_repeat('a', 31)];
        yield 'second slot too short' => [self::SLOT_A . ',short'];
        yield 'three slots' => [self::SLOT_A . ',' . self::SLOT_B . ',' . self::SLOT_A];
        yield 'a space inside' => [str_repeat('a', 20) . ' ' . str_repeat('b', 20)];
        yield 'padded with a space' => [' ' . self::SLOT_A];
        yield 'trailing newline' => [self::SLOT_A . "\n"];
        yield 'space after the comma' => [self::SLOT_A . ', ' . self::SLOT_B];
        yield 'comma only' => [','];
        yield 'commas only, long' => [str_repeat(',', 40)];
        yield 'empty second slot' => [self::SLOT_A . ','];
        yield 'empty first slot' => [',' . self::SLOT_A];
        yield 'a double quote' => [str_repeat('a', 31) . '"'];
        yield 'a backslash' => [str_repeat('a', 31) . '\\'];
        yield 'the same slot twice' => [self::SLOT_A . ',' . self::SLOT_A];
        yield 'one slot inside the other' => [self::SLOT_A . ',' . self::SLOT_A . 'extra'];
        yield 'one slot inside the other, reversed' => [self::SLOT_A . 'extra,' . self::SLOT_A];
        yield 'control character' => [str_repeat('a', 31) . "\x01"];
        yield 'non-ascii' => [str_repeat('a', 31) . 'é'];
    }

    #[DataProvider('invalidValues')]
    public function testAnInvalidValueFailsAndOnlyTheVariableNameIsReported(mixed $value): void
    {
        $env = ['CLIENT_IP_HEADER' => self::HEADER] + self::DYNAMODB + ($value === null ? [] : ['ORIGIN_VERIFY_SECRETS' => $value]);

        try {
            ApiConfig::fromEnvironment($env);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertCount(1, $e->errors);
            self::assertStringStartsWith('ORIGIN_VERIFY_SECRETS ', $e->errors[0]);
            self::assertStringContainsString('ORIGIN_VERIFY_SECRETS', $e->getMessage());
            if (is_string($value) && trim($value, ", \t\r\n") !== '') {
                self::assertStringNotContainsString(trim($value, ", \t\r\n"), $e->getMessage());
            }
        }
    }

    public function testAnErrorNeverEchoesAnyPartOfTheValue(): void
    {
        $value = self::SLOT_A . ',' . self::SLOT_B . ',' . 'third-hunter2-hunter2-hunter2-hunter2';

        try {
            ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => $value, 'CLIENT_IP_HEADER' => self::HEADER] + self::DYNAMODB);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            foreach ([self::SLOT_A, self::SLOT_B, 'hunter2'] as $fragment) {
                self::assertStringNotContainsString($fragment, $e->getMessage());
                self::assertStringNotContainsString($fragment, implode(' ', $e->errors));
            }
        }
    }

    public function testTheClientIpHeaderNameIsExposed(): void
    {
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A, 'CLIENT_IP_HEADER' => self::HEADER] + self::DYNAMODB);

        self::assertSame(self::HEADER, $config->clientIpHeader);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidHeaderNames(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'not a string' => [42];
        yield 'a space' => ['Client Ip'];
        yield 'padded' => [' CloudFront-Viewer-Address'];
        yield 'trailing newline' => ["CloudFront-Viewer-Address\n"];
        yield 'a colon' => ['CloudFront-Viewer-Address:'];
        yield 'a comma' => ['A,B'];
        yield 'non-ascii' => ['Clïent-Ip'];
        yield 'a slash' => ['A/B'];
        yield 'an underscore-free but quoted name' => ['"A"'];
        yield 'the underscore spelling' => ['CloudFront_Viewer_Address'];
        yield 'Cookie' => ['Cookie'];
        yield 'Authorization' => ['Authorization'];
        yield 'the origin verify header' => ['X-Origin-Verify'];
        yield 'X-Forwarded-For' => ['X-Forwarded-For'];
        yield 'a valid token that is not the CloudFront header' => ['X-Custom-Client'];
    }

    #[DataProvider('invalidHeaderNames')]
    public function testAnInvalidClientIpHeaderFailsAndOnlyTheVariableNameIsReported(mixed $value): void
    {
        $env = ['ORIGIN_VERIFY_SECRETS' => self::SLOT_A] + self::DYNAMODB + ($value === null ? [] : ['CLIENT_IP_HEADER' => $value]);

        try {
            ApiConfig::fromEnvironment($env);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertCount(1, $e->errors);
            self::assertStringStartsWith('CLIENT_IP_HEADER ', $e->errors[0]);
            if (is_string($value) && trim($value) !== '') {
                self::assertStringNotContainsString(trim($value), $e->getMessage());
            }
        }
    }

    public function testAllErrorsAreCollectedAndSorted(): void
    {
        try {
            ApiConfig::fromEnvironment([]);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertCount(3, $e->errors);
            self::assertSame($e->errors, array_values(array_unique($e->errors)));
            $sorted = $e->errors;
            sort($sorted);
            self::assertSame($sorted, $e->errors);
            self::assertStringStartsWith('CLIENT_IP_HEADER ', $e->errors[0]);
        }
    }

    public function testTheCloudFrontHeaderNameIsAcceptedInAnyCaseAndExposedAsWritten(): void
    {
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A, 'CLIENT_IP_HEADER' => 'cloudfront-VIEWER-address'] + self::DYNAMODB);

        self::assertSame(self::HEADER, $config->clientIpHeader);
    }

    /**
     * @return array<string, string>
     */
    private static function validEnv(): array
    {
        return ['ORIGIN_VERIFY_SECRETS' => self::SLOT_A, 'CLIENT_IP_HEADER' => self::HEADER] + self::DYNAMODB;
    }

    public function testTheDynamoDbSettingsAreExposed(): void
    {
        $config = ApiConfig::fromEnvironment(self::validEnv() + ['DYNAMODB_ENDPOINT' => 'http://dynamodb:8000', 'AWS_REGION' => 'eu-west-1']);

        self::assertSame(self::TABLE, $config->dynamoDb->tableName);
        self::assertSame('http://dynamodb:8000', $config->dynamoDb->endpoint);
        self::assertSame('eu-west-1', $config->dynamoDb->region);
    }

    public function testAMissingTableAndABadRegionAreBothReportedByNameAlongsideTheOthers(): void
    {
        $env = ['ORIGIN_VERIFY_SECRETS' => 'too-short-hunter2', 'CLIENT_IP_HEADER' => self::HEADER, 'AWS_REGION' => 'mars-central-hunter2'];

        try {
            ApiConfig::fromEnvironment($env);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            $joined = implode("\n", $e->errors);
            self::assertStringContainsString('DYNAMODB_TABLE is missing', $joined);
            self::assertStringContainsString('AWS_REGION ', $joined);
            self::assertStringContainsString('ORIGIN_VERIFY_SECRETS ', $joined);
            self::assertStringNotContainsString('hunter2', $joined);
            $sorted = $e->errors;
            sort($sorted);
            self::assertSame($sorted, $e->errors);
        }
    }

    public function testALocalEnvironmentWithoutAnEndpointFailsClosed(): void
    {
        foreach ([[], ['DYNAMODB_ENDPOINT' => '']] as $extra) {
            try {
                ApiConfig::fromEnvironment(self::validEnv() + ['APP_ENV' => 'local'] + $extra);
                self::fail('Expected InvalidConfiguration.');
            } catch (InvalidConfiguration $e) {
                self::assertCount(1, $e->errors);
                self::assertStringStartsWith('DYNAMODB_ENDPOINT ', $e->errors[0]);
            }
        }
    }

    public function testALocalEnvironmentWithAnEndpointAndANonLocalOneWithoutAreAccepted(): void
    {
        $local = ApiConfig::fromEnvironment(self::validEnv() + ['APP_ENV' => 'local', 'DYNAMODB_ENDPOINT' => 'http://dynamodb:8000']);
        $production = ApiConfig::fromEnvironment(self::validEnv() + ['APP_ENV' => 'production']);
        $unset = ApiConfig::fromEnvironment(self::validEnv());

        self::assertSame('http://dynamodb:8000', $local->dynamoDb->endpoint);
        self::assertNull($production->dynamoDb->endpoint);
        self::assertNull($unset->dynamoDb->endpoint);
    }

    public function testARealAwsEndpointIsRefusedByNameWithoutEchoingIt(): void
    {
        try {
            ApiConfig::fromEnvironment(self::validEnv() + ['DYNAMODB_ENDPOINT' => 'https://dynamodb.eu-west-1.amazonaws.com']);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertStringStartsWith('DYNAMODB_ENDPOINT ', $e->errors[0]);
            self::assertStringNotContainsString('amazonaws', implode(' ', $e->errors));
        }
    }
}
