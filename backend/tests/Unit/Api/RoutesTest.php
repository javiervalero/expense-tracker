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
        'GET /categories' => RequireSession::class,
        'POST /categories' => RequireSession::class,
        'PATCH /categories/{id}' => RequireSession::class,
        'DELETE /categories/{id}' => RequireSession::class,
        'GET /expenses' => RequireSession::class,
        'POST /expenses' => RequireSession::class,
        'GET /expenses/{id}' => RequireSession::class,
        'PATCH /expenses/{id}' => RequireSession::class,
        'DELETE /expenses/{id}' => RequireSession::class,
        'POST /expenses/{id}/receipt/upload' => RequireSession::class,
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

    public function testOnlyTheMeCategoryExpenseAndReceiptRoutesAreGuarded(): void
    {
        $guarded = [];
        foreach (self::byKey() as $key => $route) {
            if ($route->handler() instanceof RequireSession) {
                $guarded[] = $key;
            }
        }

        self::assertSame(['GET /auth/me', 'GET /categories', 'POST /categories', 'PATCH /categories/{id}', 'DELETE /categories/{id}', 'GET /expenses', 'POST /expenses', 'GET /expenses/{id}', 'PATCH /expenses/{id}', 'DELETE /expenses/{id}', 'POST /expenses/{id}/receipt/upload'], $guarded);
    }
}
