<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Api\Routes;
use App\Tests\Support\Contract\OpenApiDocument;
use PHPUnit\Framework\TestCase;

/**
 * Route parity: the routes the API serves and the operations the contract documents are the same set of
 * (method, template) pairs, in both directions. A route without an operation is undocumented behaviour; an
 * operation without a route is a promise nobody keeps.
 */
final class RoutesMatchContractTest extends TestCase
{
    public function testEveryRouteIsDocumentedInTheContract(): void
    {
        $undocumented = array_values(array_diff(self::routeKeys(), self::operationKeys()));

        self::assertSame([], $undocumented, 'Routes missing from contract/openapi.yaml: ' . implode(', ', $undocumented));
    }

    public function testEveryDocumentedOperationHasARoute(): void
    {
        $unserved = array_values(array_diff(self::operationKeys(), self::routeKeys()));

        self::assertSame([], $unserved, 'Operations in contract/openapi.yaml without a route: ' . implode(', ', $unserved));
    }

    /** @return list<string> */
    private static function routeKeys(): array
    {
        $keys = array_map(static fn($route): string => $route->method . ' ' . $route->template, Routes::all());
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private static function operationKeys(): array
    {
        $keys = array_map(
            static fn(array $operation): string => $operation[0] . ' ' . $operation[1],
            self::document()->operations(),
        );
        sort($keys);

        return $keys;
    }

    private static function document(): OpenApiDocument
    {
        return BundledSpec::document();
    }
}
