<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Input;

use App\Http\Input\FoldedText;
use PHPUnit\Framework\TestCase;

/**
 * The folding that uniqueness of names and the `q` search share. The name-level golden hashes stay in CategoryNameTest.
 */
final class FoldedTextTest extends TestCase
{
    public function testItFoldsCase(): void
    {
        self::assertSame('food', FoldedText::of('FoOd'));
        self::assertSame('strasse', FoldedText::of("Stra\u{00DF}e"));
    }

    public function testItCollapsesRunsOfSpaceSeparatorsToOneSpace(): void
    {
        self::assertSame('eating out', FoldedText::of("Eating\u{00A0}\u{00A0}  OUT"));
    }

    public function testItDropsTheAllowedInvisibleCharacters(): void
    {
        self::assertSame('cafe', FoldedText::of("ca\u{200B}\u{200C}\u{200D}\u{200E}\u{200F}\u{061C}fe"));
    }

    public function testItKeepsOtherTextAsItIs(): void
    {
        self::assertSame("caf\u{00E9} #1", FoldedText::of("Caf\u{00C9} #1"));
        self::assertSame('', FoldedText::of(''));
    }
}
