<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories\Http;

use App\Categories\Http\CategoryFieldMessages;
use App\Http\Input\TextViolation;
use PHPUnit\Framework\TestCase;

final class CategoryFieldMessagesTest extends TestCase
{
    public function testEveryViolationHasADistinctMessage(): void
    {
        $messages = array_map(CategoryFieldMessages::name(...), TextViolation::cases());

        self::assertSame($messages, array_values(array_unique($messages)));
        self::assertStringContainsString('at most 50 characters', CategoryFieldMessages::name(TextViolation::TooLong));
    }

    public function testNoMessageEchoesAnInputMarker(): void
    {
        foreach (TextViolation::cases() as $violation) {
            self::assertStringNotContainsString('secret-marker', CategoryFieldMessages::name($violation));
        }
    }
}
