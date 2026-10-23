<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Routing;

use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Routing\RouteId;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteIdTest extends TestCase
{
    private const ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';

    /**
     * @param array<string, string> $params
     */
    private static function request(array $params): Request
    {
        return new Request('GET', '/expenses/x', [], [], '', [Request::ROUTE_PARAMS => $params]);
    }

    public function testAWellFormedIdIsReturned(): void
    {
        self::assertSame(self::ID, RouteId::uuidOrNotFound(self::request(['id' => self::ID])));
    }

    public function testANamedParameterIsRead(): void
    {
        self::assertSame(self::ID, RouteId::uuidOrNotFound(self::request(['expenseId' => self::ID]), 'expenseId'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => [strtoupper(self::ID)];
        yield 'version 1' => ['9d1c7a52-0b3e-1f68-8a21-7e5d4c3b2a10'];
        yield 'nil' => ['00000000-0000-0000-0000-000000000000'];
        yield 'separator' => ['a#b'];
        yield 'braces' => ['{' . self::ID . '}'];
        yield 'padded' => [' ' . self::ID];
        yield 'trailing newline' => [self::ID . "\n"];
        yield 'unicode' => ["9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a1\u{0661}"];
    }

    #[DataProvider('badIds')]
    public function testAMalformedIdGivesTheGeneric404BeforeAnyHandlerCodeRuns(string $id): void
    {
        $store = new class {
            public int $calls = 0;

            public function read(string $id): void
            {
                ++$this->calls;
            }
        };

        try {
            $store->read(RouteId::uuidOrNotFound(self::request(['id' => $id])));
            self::fail('A malformed id must give 404.');
        } catch (HttpProblem $problem) {
            self::assertSame(404, $problem->problem->status);
            self::assertSame('The resource does not exist.', $problem->problem->detail);
        }
        self::assertSame(0, $store->calls);
    }

    public function testANotFoundForAMalformedIdIsIdenticalToTheOneForAnotherUsersResource(): void
    {
        try {
            RouteId::uuidOrNotFound(self::request(['id' => 'nope']));
            self::fail('Expected 404.');
        } catch (HttpProblem $problem) {
            self::assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'The resource does not exist.'], $problem->problem->toArray());
        }
    }

    public function testAMissingParameterIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);

        RouteId::uuidOrNotFound(self::request(['other' => self::ID]));
    }

    public function testMissingRouteParametersAreAProgrammingError(): void
    {
        $this->expectException(LogicException::class);

        RouteId::uuidOrNotFound(new Request('GET', '/x', [], [], ''));
    }
}
