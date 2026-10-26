<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Categories\Http\CategoryInput;
use App\Expenses\Http\ExpensePatchInput;
use App\Expenses\Http\NewExpenseInput;
use App\Http\Input\CategoryIdMember;
use App\Http\Input\StringMember;
use App\Http\Input\VersionMember;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;

/**
 * A parameter that receives the raw decoded request body must be `#[\SensitiveParameter]`, so a stack trace of an
 * unexpected failure never prints what the client sent.
 */
final class BodyParametersAreSensitiveTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function inputClasses(): iterable
    {
        yield 'category input' => [CategoryInput::class];
        yield 'new expense input' => [NewExpenseInput::class];
        yield 'expense patch input' => [ExpensePatchInput::class];
        yield 'string member' => [StringMember::class];
        yield 'version member' => [VersionMember::class];
        yield 'category id member' => [CategoryIdMember::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('inputClasses')]
    public function testEveryBodyParameterIsMarkedSensitive(string $class): void
    {
        $bodyParameters = [];
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() === 'body') {
                    $bodyParameters[] = $parameter;
                }
            }
        }

        self::assertNotSame([], $bodyParameters, 'no body parameter found: the test would prove nothing');
        $unmarked = array_map(
            static fn(ReflectionParameter $parameter): string => self::describe($parameter),
            array_filter(
                $bodyParameters,
                static fn(ReflectionParameter $parameter): bool => $parameter->getAttributes(SensitiveParameter::class) === [],
            ),
        );
        self::assertSame([], array_values($unmarked), 'body parameters without #[\SensitiveParameter]');
    }

    private static function describe(ReflectionParameter $parameter): string
    {
        $method = $parameter->getDeclaringFunction();

        return $method instanceof ReflectionMethod ? $method->class . '::' . $method->name : $method->name;
    }
}
