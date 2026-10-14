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

    public function testOneSlotIsAccepted(): void
    {
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A]);

        self::assertSame([self::SLOT_A], $config->originVerifySecrets);
    }

    public function testTwoSlotsAreAcceptedInOrder(): void
    {
        $config = ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => self::SLOT_A . ',' . self::SLOT_B]);

        self::assertSame([self::SLOT_A, self::SLOT_B], $config->originVerifySecrets);
    }

    public function testASlotOfExactlyThirtyTwoVisibleCharactersIsAccepted(): void
    {
        $slot = str_repeat('~', 32);

        self::assertSame([$slot], ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => $slot])->originVerifySecrets);
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
        yield 'control character' => [str_repeat('a', 31) . "\x01"];
        yield 'non-ascii' => [str_repeat('a', 31) . 'é'];
    }

    #[DataProvider('invalidValues')]
    public function testAnInvalidValueFailsAndOnlyTheVariableNameIsReported(mixed $value): void
    {
        $env = $value === null ? [] : ['ORIGIN_VERIFY_SECRETS' => $value];

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
            ApiConfig::fromEnvironment(['ORIGIN_VERIFY_SECRETS' => $value]);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            foreach ([self::SLOT_A, self::SLOT_B, 'hunter2'] as $fragment) {
                self::assertStringNotContainsString($fragment, $e->getMessage());
                self::assertStringNotContainsString($fragment, implode(' ', $e->errors));
            }
        }
    }
}
