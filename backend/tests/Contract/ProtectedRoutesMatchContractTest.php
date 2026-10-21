<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Api\Routes;
use App\Auth\Session\RequireSession;
use App\Tests\Support\Api\OfflineServices;
use PHPUnit\Framework\TestCase;

/**
 * A7: there is no global authentication middleware, so a forgotten `RequireSession` would leave a route public with
 * nothing else to catch it. The routes whose handler is a RequireSession must be exactly the operations that declare
 * a non-empty `security` in the contract, in both directions.
 */
final class ProtectedRoutesMatchContractTest extends TestCase
{
    public function testTheGuardedRoutesAreTheOperationsThatDeclareSecurity(): void
    {
        $guarded = self::guardedRoutes();
        $declared = self::declaredOperations();

        self::assertNotSame([], $declared, 'The contract protects no operation: /auth/me should.');
        self::assertSame(
            [],
            array_values(array_diff($declared, $guarded)),
            'Operations with security but no RequireSession on the route: ' . implode(', ', array_diff($declared, $guarded)),
        );
        self::assertSame(
            [],
            array_values(array_diff($guarded, $declared)),
            'Guarded routes whose operation declares no security: ' . implode(', ', array_diff($guarded, $declared)),
        );
    }

    /** @return list<string> */
    private static function guardedRoutes(): array
    {
        $keys = [];
        foreach (Routes::all(OfflineServices::create()) as $route) {
            if ($route->handler() instanceof RequireSession) {
                $keys[] = $route->method . ' ' . $route->template;
            }
        }
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private static function declaredOperations(): array
    {
        $document = BundledSpec::document();
        $keys = [];
        foreach ($document->operations() as [$method, $template]) {
            if (($document->security($method, $template) ?? []) !== []) {
                $keys[] = "$method $template";
            }
        }
        sort($keys);

        return $keys;
    }
}
