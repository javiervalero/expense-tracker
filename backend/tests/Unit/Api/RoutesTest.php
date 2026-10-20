<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Routes;
use App\Auth\Http\LoginHandler;
use App\Auth\Http\LogoutHandler;
use App\Auth\Http\RegisterHandler;
use App\Auth\Session\RequireSession;
use App\Health\HealthHandler;
use App\Http\Routing\Route;
use App\Tests\Support\Api\OfflineServices;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase
{
    private const array EXPECTED = [
        'GET /health' => HealthHandler::class,
        'HEAD /health' => HealthHandler::class,
        'POST /auth/register' => RegisterHandler::class,
        'POST /auth/login' => LoginHandler::class,
        'GET /auth/me' => RequireSession::class,
        'POST /auth/logout' => LogoutHandler::class,
    ];

    /** @return array<string, Route> */
    private static function byKey(): array
    {
        $byKey = [];
        foreach (Routes::all(OfflineServices::create()) as $route) {
            $byKey[$route->method . ' ' . $route->template] = $route;
        }

        return $byKey;
    }

    public function testTheTableHasExactlyTheExpectedRoutes(): void
    {
        self::assertSame(array_keys(self::EXPECTED), array_keys(self::byKey()));
    }

    public function testEveryRouteBuildsTheExpectedHandlerClass(): void
    {
        foreach (self::byKey() as $key => $route) {
            self::assertSame(self::EXPECTED[$key], $route->handler()::class, $key);
        }
    }

    public function testCallingAFactoryTwiceGivesTheSameClassSoFactoriesDoNotBranch(): void
    {
        foreach (self::byKey() as $key => $route) {
            self::assertSame($route->handler()::class, $route->handler()::class, $key);
        }
    }

    public function testOnlyTheMeRouteIsGuarded(): void
    {
        $guarded = [];
        foreach (self::byKey() as $key => $route) {
            if ($route->handler() instanceof RequireSession) {
                $guarded[] = $key;
            }
        }

        self::assertSame(['GET /auth/me'], $guarded);
    }
}
