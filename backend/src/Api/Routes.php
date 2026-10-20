<?php

declare(strict_types=1);

namespace App\Api;

use App\Auth\Http\LoginHandler;
use App\Auth\Http\LogoutHandler;
use App\Auth\Http\MeHandler;
use App\Auth\Http\RegisterHandler;
use App\Auth\Password\PasswordHasher;
use App\Auth\Password\PasswordPolicy;
use App\Auth\Session\CarriedSession;
use App\Auth\Session\RequireSession;
use App\Auth\Session\SessionAuthenticator;
use App\Auth\Session\SessionRepository;
use App\Auth\Throttle\LoginThrottle;
use App\Auth\Throttle\RegisterThrottle;
use App\Auth\Throttle\ThrottleCounter;
use App\Auth\User\UserRepository;
use App\Health\HealthHandler;
use App\Http\Routing\Route;

/**
 * The route table of the API (templates are relative to `/api/v1`). Every route holds a factory, not a handler: the
 * dispatcher builds the handler (and the stores behind it) of the one route it matched, so a request to `/health`
 * builds nothing else.
 */
final class Routes
{
    /**
     * @param ApiServices $services what the handlers share; the health route needs none of it, the auth routes use it
     *
     * @return list<Route>
     */
    public static function all(ApiServices $services): array
    {
        // Route factories must not branch, and RequireSession must stay the outermost wrapper of a protected handler
        // (ProtectedRoutesMatchContractTest relies on both).
        return [
            new Route('GET', '/health', static fn(): HealthHandler => new HealthHandler()),
            new Route('HEAD', '/health', static fn(): HealthHandler => new HealthHandler()),
            new Route('POST', '/auth/register', static fn(): RegisterHandler => self::register($services)),
            new Route('POST', '/auth/login', static fn(): LoginHandler => self::login($services)),
            new Route('GET', '/auth/me', static fn(): RequireSession => self::me($services)),
            new Route('POST', '/auth/logout', static fn(): LogoutHandler => self::logout($services)),
        ];
    }

    private static function sessions(ApiServices $services): SessionRepository
    {
        return new SessionRepository($services->items, $services->clock);
    }

    private static function register(ApiServices $services): RegisterHandler
    {
        $sessions = self::sessions($services);

        return new RegisterHandler(
            new RegisterThrottle(new ThrottleCounter($services->items, $services->clock)),
            new PasswordPolicy(),
            PasswordHasher::production(),
            new UserRepository($services->items),
            $sessions,
            new CarriedSession($sessions, $services->logger),
            $services->ids,
            $services->clock,
            $services->logger,
        );
    }

    private static function login(ApiServices $services): LoginHandler
    {
        $sessions = self::sessions($services);

        return new LoginHandler(
            new LoginThrottle(new ThrottleCounter($services->items, $services->clock), $services->logger),
            new PasswordPolicy(),
            PasswordHasher::production(),
            new UserRepository($services->items),
            $sessions,
            new CarriedSession($sessions, $services->logger),
            $services->logger,
        );
    }

    private static function logout(ApiServices $services): LogoutHandler
    {
        return new LogoutHandler(new CarriedSession(self::sessions($services), $services->logger));
    }

    private static function me(ApiServices $services): RequireSession
    {
        $sessions = self::sessions($services);

        return new RequireSession(
            new MeHandler(new UserRepository($services->items), $sessions, $services->logger),
            new SessionAuthenticator($sessions, $services->clock, $services->logger),
        );
    }
}
