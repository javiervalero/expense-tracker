<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses;

use App\Expenses\Amount;
use App\Expenses\AmountViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    public function testTheBoundsPass(): void
    {
        $min = Amount::parse(1);
        $max = Amount::parse(100000000);

        self::assertInstanceOf(Amount::class, $min);
        self::assertSame(1, $min->cents());
        self::assertInstanceOf(Amount::class, $max);
        self::assertSame(100_000_000, $max->cents());
    }

    /**
     * @return array<string, array{mixed, AmountViolation}>
     */
    public static function refused(): array
    {
        return [
            'zero' => [0, AmountViolation::TooSmall],
            'negative' => [-1, AmountViolation::TooSmall],
            'PHP_INT_MIN' => [PHP_INT_MIN, AmountViolation::TooSmall],
            'one over the maximum' => [100000001, AmountViolation::TooLarge],
            'PHP_INT_MAX' => [PHP_INT_MAX, AmountViolation::TooLarge],
            'float with zero fraction' => [12.0, AmountViolation::NotAnInteger],
            'float' => [12.5, AmountViolation::NotAnInteger],
            'numeric string' => ['12', AmountViolation::NotAnInteger],
            'bool' => [true, AmountViolation::NotAnInteger],
            'null' => [null, AmountViolation::NotAnInteger],
            'bigint as string' => ['99999999999999999999', AmountViolation::NotAnInteger],
            'list' => [[1], AmountViolation::NotAnInteger],
        ];
    }

    #[DataProvider('refused')]
    public function testItRefusesWithTheMatchingViolation(mixed $input, AmountViolation $expected): void
    {
        self::assertSame($expected, Amount::parse($input));
    }
}
