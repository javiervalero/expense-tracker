<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Expenses\AmountViolation;
use App\Expenses\Http\ExpenseFieldMessages;
use App\Http\Input\TextViolation;
use PHPUnit\Framework\TestCase;

final class ExpenseFieldMessagesTest extends TestCase
{
    public function testEveryAmountViolationHasADistinctMessageWithTheLimits(): void
    {
        $messages = array_map(ExpenseFieldMessages::amount(...), AmountViolation::cases());

        self::assertSame($messages, array_values(array_unique($messages)));
        self::assertSame('Must be a whole number of cents.', ExpenseFieldMessages::amount(AmountViolation::NotAnInteger));
        self::assertStringContainsString('at least 1', ExpenseFieldMessages::amount(AmountViolation::TooSmall));
        self::assertStringContainsString('100000000', ExpenseFieldMessages::amount(AmountViolation::TooLarge));
    }

    public function testEveryDescriptionViolationHasADistinctMessage(): void
    {
        $messages = array_map(ExpenseFieldMessages::description(...), TextViolation::cases());

        self::assertSame($messages, array_values(array_unique($messages)));
        self::assertStringContainsString('at most 200 characters', ExpenseFieldMessages::description(TextViolation::TooLong));
    }

    public function testTheDateMessageNamesTheFormatAndRange(): void
    {
        self::assertSame(
            'Must be a valid date in the form YYYY-MM-DD, from 2000-01-01 to 2099-12-31.',
            ExpenseFieldMessages::date(),
        );
    }

    public function testNoMessageEchoesAnInputMarker(): void
    {
        $marker = 'secret-marker';
        $messages = array_map(ExpenseFieldMessages::description(...), TextViolation::cases());
        $messages[] = ExpenseFieldMessages::date();
        $messages = [...$messages, ...array_map(ExpenseFieldMessages::amount(...), AmountViolation::cases())];

        foreach ($messages as $message) {
            self::assertStringNotContainsString($marker, $message);
        }
    }
}
