<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses;

use App\Expenses\Description;
use App\Http\Input\TextViolation;
use PHPUnit\Framework\TestCase;

final class DescriptionTest extends TestCase
{
    public function testItTrimsAndKeepsTheText(): void
    {
        $description = Description::parse("  Lunch #3 \u{1F37D}  ");

        self::assertInstanceOf(Description::class, $description);
        self::assertSame("Lunch #3 \u{1F37D}", $description->value());
    }

    public function testItAccepts200AndRefuses201Characters(): void
    {
        self::assertInstanceOf(Description::class, Description::parse(str_repeat('a', 200)));
        self::assertSame(TextViolation::TooLong, Description::parse(str_repeat('a', 201)));
    }

    public function testItRefusesEmptyControlAndBidiText(): void
    {
        self::assertSame(TextViolation::Empty, Description::parse('   '));
        self::assertSame(TextViolation::ControlCharacter, Description::parse("a\x00"));
        self::assertSame(TextViolation::BidiControl, Description::parse("a\u{202E}b"));
    }
}
