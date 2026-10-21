<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Session;

use App\Auth\Session\SessionToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionTokenTest extends TestCase
{
    /** 43 base64url characters. */
    private const FIXED = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

    /** SHA-256 of FIXED, computed independently with `sha256sum` and Python's hashlib. */
    private const FIXED_SHA256 = '46a2199782c8827f0ac56f503be9d39efee97f40a736b92cc7d7c5f825cfd851';

    public function testGenerateGives43CharactersOfTheBase64urlAlphabet(): void
    {
        $value = SessionToken::generate()->cookieValue();

        self::assertSame(43, strlen($value));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $value);
    }

    public function testGenerateGivesThe32BytesBackWhenDecoded(): void
    {
        $value = SessionToken::generate()->cookieValue();

        self::assertSame(32, strlen((string) base64_decode(strtr($value, '-_', '+/') . '=', true)));
    }

    public function testThousandGeneratedValuesAreUnique(): void
    {
        $values = [];
        for ($i = 0; $i < 1000; ++$i) {
            $values[SessionToken::generate()->cookieValue()] = true;
        }

        self::assertCount(1000, $values);
    }

    public function testHashIsTheKnownLowerCaseSha256HexOfTheCookieValue(): void
    {
        $token = SessionToken::fromCookieValue(self::FIXED);

        self::assertNotNull($token);
        self::assertSame(self::FIXED_SHA256, $token->hash());
        self::assertSame(hash('sha256', self::FIXED), $token->hash());
    }

    public function testHashHas64LowerCaseHexCharacters(): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', SessionToken::generate()->hash());
    }

    public function testFromCookieValueAcceptsAValidValueAndKeepsIt(): void
    {
        $token = SessionToken::fromCookieValue('-_' . str_repeat('A0', 20) . 'z');

        self::assertSame('-_' . str_repeat('A0', 20) . 'z', $token?->cookieValue());
    }

    public function testFromCookieValueRoundTripsAGeneratedToken(): void
    {
        $generated = SessionToken::generate();

        self::assertSame($generated->hash(), SessionToken::fromCookieValue($generated->cookieValue())?->hash());
    }

    #[DataProvider('invalidValues')]
    public function testFromCookieValueRefusesAnythingElse(string $value): void
    {
        self::assertNull(SessionToken::fromCookieValue($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        $valid = self::FIXED;
        yield 'empty' => [''];
        yield '42 characters' => [substr($valid, 0, 42)];
        yield '44 characters' => [$valid . 'A'];
        yield 'plus' => ['+' . substr($valid, 1)];
        yield 'slash' => ['/' . substr($valid, 1)];
        yield 'padding' => [substr($valid, 0, 42) . '='];
        yield 'non-ascii' => ["\u{e9}" . substr($valid, 2)];
        yield 'non-ascii emoji' => [substr($valid, 0, 39) . "\u{1F600}"];
        yield 'space' => [' ' . substr($valid, 1)];
        yield 'leading space, 44 characters' => [' ' . $valid];
        yield 'trailing newline, 44 characters' => [$valid . "\n"];
        yield 'trailing newline, 43 characters' => [substr($valid, 0, 42) . "\n"];
        yield 'tab' => ["\t" . substr($valid, 1)];
        yield 'null byte' => ["\0" . substr($valid, 1)];
        yield 'quoted' => ['"' . substr($valid, 1, 41) . '"'];
    }

    public function testValueNeverAppearsInVarDumpPrintRVarExportOrJson(): void
    {
        $token = SessionToken::fromCookieValue(self::FIXED);
        self::assertNotNull($token);

        ob_start();
        var_dump($token);
        $varDump = (string) ob_get_clean();
        $outputs = [
            'var_dump' => $varDump,
            'print_r' => print_r($token, true),
            'var_export' => var_export($token, true),
            'var_export array' => var_export([$token], true),
            'debug_zval_dump' => self::captureDebugZvalDump($token),
        ];

        foreach ($outputs as $label => $output) {
            self::assertStringNotContainsString(self::FIXED, $output, $label);
            self::assertStringNotContainsString(substr(self::FIXED, 5, 12), $output, $label);
        }
    }

    public function testJsonEncodeIsRefusedWithAMessageThatHasNoValue(): void
    {
        $token = SessionToken::fromCookieValue(self::FIXED);
        self::assertNotNull($token);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A session token must not be encoded as JSON.');

        json_encode(['t' => $token]);
    }

    public function testSerializeIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        serialize(SessionToken::generate());
    }

    public function testCloneIsRefused(): void
    {
        $token = SessionToken::generate();
        $this->expectException(\LogicException::class);

        $copy = clone $token;
    }

    public function testUnserializeIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        unserialize('O:' . strlen(SessionToken::class) . ':"' . SessionToken::class . '":0:{}');
    }

    public function testThereIsNoStringConversion(): void
    {
        $class = new \ReflectionClass(SessionToken::class);

        self::assertFalse($class->hasMethod('__toString'));
        self::assertFalse($class->implementsInterface(\Stringable::class));
    }

    public function testTheCookieValueIsNotAnObjectPropertyAndCastingRevealsNothing(): void
    {
        $token = SessionToken::fromCookieValue(self::FIXED);
        self::assertNotNull($token);

        self::assertStringNotContainsString(self::FIXED, print_r(get_object_vars($token), true));
        self::assertStringNotContainsString(self::FIXED, print_r((array) $token, true));
    }

    public function testTwoLiveTokensFromDifferentValuesKeepDistinctValuesAndHashes(): void
    {
        $first = SessionToken::fromCookieValue(self::FIXED);
        $second = SessionToken::fromCookieValue(strrev(self::FIXED));
        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertSame(self::FIXED, $first->cookieValue());
        self::assertSame(strrev(self::FIXED), $second->cookieValue());
        self::assertNotSame($first->hash(), $second->hash());
    }

    public function testReadingTheValueOfATokenWithoutOneFailsWithoutRevealingAnything(): void
    {
        $token = (new \ReflectionClass(SessionToken::class))->newInstanceWithoutConstructor();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A session token has no value.');

        $token->cookieValue();
    }

    public function testUnserializeTakesSensitiveData(): void
    {
        $method = new \ReflectionMethod(SessionToken::class, '__unserialize');

        self::assertCount(1, $method->getParameters()[0]->getAttributes(\SensitiveParameter::class));
    }

    public function testEveryParameterThatTakesTheValueIsMarkedSensitive(): void
    {
        $factory = new \ReflectionMethod(SessionToken::class, 'fromCookieValue');
        $constructor = new \ReflectionMethod(SessionToken::class, '__construct');

        foreach ([$factory, $constructor] as $method) {
            $attributes = $method->getParameters()[0]->getAttributes(\SensitiveParameter::class);
            self::assertCount(1, $attributes, $method->getName());
        }
    }

    private static function captureDebugZvalDump(object $value): string
    {
        ob_start();
        debug_zval_dump($value);

        return (string) ob_get_clean();
    }
}
