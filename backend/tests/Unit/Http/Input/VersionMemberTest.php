<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Input;

use App\Http\Input\VersionMember;
use App\Http\Problem\FieldError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionMemberTest extends TestCase
{
    public function testReturnsAnIntegerOfAtLeastOne(): void
    {
        self::assertSame(1, VersionMember::required(['version' => 1]));
        self::assertSame(PHP_INT_MAX, VersionMember::required(['version' => PHP_INT_MAX]));
    }

    public function testReportsAnAbsentMember(): void
    {
        $error = VersionMember::required(['name' => 'x']);

        self::assertInstanceOf(FieldError::class, $error);
        self::assertSame('/version', $error->pointer);
        self::assertSame('This field is required.', $error->detail);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonIntegers(): iterable
    {
        yield 'null' => [null];
        yield 'float' => [12.0];
        yield 'numeric string' => ['3'];
        yield 'huge integer kept as a string' => ['9223372036854775808'];
        yield 'bool' => [true];
        yield 'array' => [[1]];
    }

    #[DataProvider('nonIntegers')]
    public function testRefusesANonInteger(mixed $value): void
    {
        $error = VersionMember::required(['version' => $value]);

        self::assertInstanceOf(FieldError::class, $error);
        self::assertSame('/version', $error->pointer);
        self::assertSame('Must be an integer.', $error->detail);
    }

    #[DataProvider('belowOne')]
    public function testRefusesAnIntegerBelowOne(int $value): void
    {
        $error = VersionMember::required(['version' => $value]);

        self::assertInstanceOf(FieldError::class, $error);
        self::assertSame('/version', $error->pointer);
        self::assertSame('Must be at least 1.', $error->detail);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function belowOne(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'min int' => [PHP_INT_MIN];
    }
}
