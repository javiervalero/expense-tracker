<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Input;

use App\Http\Input\MemberErrors;
use App\Http\Problem\FieldError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemberErrorsTest extends TestCase
{
    public function testAFieldErrorBecomesAOneItemList(): void
    {
        $error = new FieldError('/name', 'Bad.');

        self::assertSame([$error], MemberErrors::of($error));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function validValues(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['x'];
        yield 'int' => [3];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('validValues')]
    public function testAnythingElseIsNoError(mixed $member): void
    {
        self::assertSame([], MemberErrors::of($member));
    }
}
