<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Input;

use App\Http\Input\Pcre;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PcreTest extends TestCase
{
    public function testMatchesReportsAMatchAndAMiss(): void
    {
        self::assertTrue(Pcre::matches('/b/', 'abc'));
        self::assertFalse(Pcre::matches('/x/', 'abc'));
    }

    public function testMatchesFailsClosedOnAPcreError(): void
    {
        try {
            Pcre::matches('/./u', "secret-marker\xC3\x28");
            self::fail('A PCRE error must throw.');
        } catch (RuntimeException $failure) {
            self::assertSame('A text check failed: Malformed UTF-8 characters, possibly incorrectly encoded', $failure->getMessage());
            self::assertStringNotContainsString('secret-marker', $failure->getMessage());
        }
    }

    public function testReplaceRewritesAndFailsClosedOnAPcreError(): void
    {
        self::assertSame('a b', Pcre::replace('/-/', ' ', 'a-b'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A text rewrite failed: Malformed UTF-8');

        Pcre::replace('/./u', '', "\xC3\x28");
    }
}
