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

    public function testOneSlotIsAccepted(): void
    {
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A, 'CLIENT_IP_HEADER' => self::HEADER]);

        self::assertSame([self::SLOT_A], $config->originVerifySecrets);
    }

    public function testTwoSlotsAreAcceptedInOrder(): void
    {
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A . ',' . self::SLOT_B, 'CLIENT_IP_HEADER' => self::HEADER]);

        self::assertSame([self::SLOT_A, self::SLOT_B], $config->originVerifySecrets);
    }

    public function testASlotOfExactlyThirtyTwoVisibleCharactersIsAccepted(): void
    {
        $slot = str_repeat('~', 32);

        self::assertSame([$slot], ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => $slot, 'CLIENT_IP_HEADER' => self::HEADER])->originVerifySecrets);
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
        $env = ['CLIENT_IP_HEADER' => self::HEADER] + ($value === null ? [] : ['ORIGIN_VERIFY_SECRETS' => $value]);

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
            ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => $value, 'CLIENT_IP_HEADER' => self::HEADER]);
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
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A, 'CLIENT_IP_HEADER' => self::HEADER]);

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
    }

    #[DataProvider('invalidHeaderNames')]
    public function testAnInvalidClientIpHeaderFailsAndOnlyTheVariableNameIsReported(mixed $value): void
    {
        $env = ['ORIGIN_VERIFY_SECRETS' => self::SLOT_A] + ($value === null ? [] : ['CLIENT_IP_HEADER' => $value]);

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
            self::assertCount(2, $e->errors);
            self::assertSame($e->errors, array_values(array_unique($e->errors)));
            $sorted = $e->errors;
            sort($sorted);
            self::assertSame($sorted, $e->errors);
            self::assertStringStartsWith('CLIENT_IP_HEADER ', $e->errors[0]);
        }
    }

    public function testAHeaderNameWithTokenPunctuationIsAccepted(): void
    {
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A, 'CLIENT_IP_HEADER' => "X-Client_Ip.1~!#$%&'*+^`|"]);

        self::assertSame("X-Client_Ip.1~!#$%&'*+^`|", $config->clientIpHeader);
    }
}
