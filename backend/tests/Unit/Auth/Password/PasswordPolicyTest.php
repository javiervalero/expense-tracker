<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Password;

use App\Auth\Password\PasswordPolicy;
use App\Auth\Password\PasswordViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PasswordPolicy();
    }

    public function testElevenCharactersAreTooShort(): void
    {
        self::assertSame(PasswordViolation::TooShort, $this->policy->violation(str_repeat('a', 11)));
    }

    public function testTheEmptyPasswordIsTooShort(): void
    {
        self::assertSame(PasswordViolation::TooShort, $this->policy->violation(''));
    }

    public function testTwelveCharactersPass(): void
    {
        self::assertNull($this->policy->violation('qwfpgjluy;ar'));
    }

    public function test128CharactersPass(): void
    {
        self::assertNull($this->policy->violation(str_repeat('b', 128)));
    }

    public function test129CharactersAreTooLong(): void
    {
        self::assertSame(PasswordViolation::TooLong, $this->policy->violation(str_repeat('b', 129)));
    }

    public function testMultibyteCharactersCountAsOne(): void
    {
        self::assertNull($this->policy->violation(str_repeat("\u{e9}", 12)));
        self::assertSame(PasswordViolation::TooShort, $this->policy->violation(str_repeat("\u{e9}", 11)));
        self::assertNull($this->policy->violation(str_repeat("\u{4e2d}", 128)));
        self::assertSame(PasswordViolation::TooLong, $this->policy->violation(str_repeat("\u{4e2d}", 129)));
    }

    public function testSpacesAreAllowedAnywhere(): void
    {
        self::assertNull($this->policy->violation('  two  spaces  around  '));
        self::assertNull($this->policy->violation('a b c d e f g h i j'));
    }

    public function testEmojiAreAllowedAndCountedAsOne(): void
    {
        self::assertNull($this->policy->violation(str_repeat("\u{1F600}", 12)));
        self::assertSame(PasswordViolation::TooShort, $this->policy->violation(str_repeat("\u{1F600}", 11)));
    }

    #[DataProvider('controlCharacters')]
    public function testAControlCharacterIsRefused(string $character): void
    {
        self::assertSame(PasswordViolation::ControlCharacter, $this->policy->violation('abcdef' . $character . 'ghijkl'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function controlCharacters(): iterable
    {
        yield 'NUL' => ["\x00"];
        yield 'unit separator' => ["\x1F"];
        yield 'DEL' => ["\x7F"];
        yield 'tab' => ["\t"];
        yield 'newline' => ["\n"];
        yield 'C1 control' => ["\u{85}"];
    }

    public function testInvalidUtf8IsRefused(): void
    {
        self::assertSame(PasswordViolation::InvalidEncoding, $this->policy->violation("abcdefghijk\xC3"));
        self::assertSame(PasswordViolation::InvalidEncoding, $this->policy->violation("abcdefghijkl\xFF\xFE"));
    }

    public function testAPasswordBothTooLongAndWithAControlCharacterIsTooLong(): void
    {
        self::assertSame(PasswordViolation::TooLong, $this->policy->violation(str_repeat('a', 129) . "\x00"));
    }

    public function testAPasswordBothTooShortAndWithAControlCharacterIsTooShort(): void
    {
        self::assertSame(PasswordViolation::TooShort, $this->policy->violation("a\x00b"));
    }

    #[DataProvider('reasonCodes')]
    public function testTheReasonCodeIsTheStableString(string $code, PasswordViolation $expected): void
    {
        self::assertSame($expected, PasswordViolation::from($code));
        self::assertSame($code, $expected->value);
    }

    /**
     * @return iterable<string, array{string, PasswordViolation}>
     */
    public static function reasonCodes(): iterable
    {
        yield 'invalid encoding' => ['invalid_encoding', PasswordViolation::InvalidEncoding];
        yield 'too short' => ['too_short', PasswordViolation::TooShort];
        yield 'too long' => ['too_long', PasswordViolation::TooLong];
        yield 'control character' => ['control_character', PasswordViolation::ControlCharacter];
    }

    /** Invalid UTF-8 has no character count: it is measured in bytes, so oversized junk is still bounded. */
    public function testInvalidUtf8IsMeasuredInBytesForTheLoginLimit(): void
    {
        self::assertFalse($this->policy->exceedsLoginLimit("abc\xC3"));
        self::assertFalse($this->policy->exceedsLoginLimit(str_repeat("\xFF", 128)));
        self::assertTrue($this->policy->exceedsLoginLimit(str_repeat("\xFF", 129)));
    }

    public function testExceedsLoginLimitOnlyAboveTheMaximum(): void
    {
        self::assertFalse($this->policy->exceedsLoginLimit(str_repeat('a', 128)));
        self::assertTrue($this->policy->exceedsLoginLimit(str_repeat('a', 129)));
        self::assertFalse($this->policy->exceedsLoginLimit(str_repeat("\u{1F600}", 128)));
        self::assertTrue($this->policy->exceedsLoginLimit(str_repeat("\u{1F600}", 129)));
    }

    public function testLoginNeverChecksLengthBelowTheMinimumOrControlCharacters(): void
    {
        self::assertFalse($this->policy->exceedsLoginLimit(''));
        self::assertFalse($this->policy->exceedsLoginLimit('short'));
        self::assertFalse($this->policy->exceedsLoginLimit("with\x00control"));
    }

    public function testEveryParameterThatTakesAPasswordIsMarkedSensitive(): void
    {
        foreach (['violation', 'exceedsLoginLimit'] as $name) {
            $parameter = (new \ReflectionMethod(PasswordPolicy::class, $name))->getParameters()[0];

            self::assertCount(1, $parameter->getAttributes(\SensitiveParameter::class), $name);
        }
    }
}
