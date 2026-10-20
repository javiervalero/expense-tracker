<?php

declare(strict_types=1);

namespace App\Api;

use App\Auth\Http\LoginHandler;
use App\Auth\Http\RegisterHandler;
use App\Auth\Password\PasswordHasher;
use App\Auth\Password\PasswordPolicy;
use App\Auth\Session\CarriedSession;
use App\Auth\Session\SessionRepository;
use App\Auth\Throttle\LoginThrottle;
use App\Auth\Throttle\RegisterThrottle;
use App\Auth\Throttle\ThrottleCounter;
use App\Auth\User\UserRepository;
use App\Health\HealthHandler;
use App\Http\Routing\Route;

/** The route table of the API (templates are relative to `/api/v1`). */
final class Routes
{
    /**
     * @param ApiServices $services what the handlers share; the health route needs none of it, the auth routes use it
     *
     * @return list<Route>
     */
    public static function all(ApiServices $services): array
    {
        $health = new HealthHandler();
        $sessions = new SessionRepository($services->items, $services->clock);
        $throttleCounter = new ThrottleCounter($services->items, $services->clock);
        $carriedSession = new CarriedSession($sessions, $services->logger);
        $register = new RegisterHandler(
            new RegisterThrottle($throttleCounter),
            new PasswordPolicy(),
            PasswordHasher::production(),
            new UserRepository($services->items),
            $sessions,
            $carriedSession,
            $services->ids,
            $services->clock,
            $services->logger,
        );
        $login = new LoginHandler(
            new LoginThrottle($throttleCounter, $services->logger),
            new PasswordPolicy(),
            PasswordHasher::production(),
            new UserRepository($services->items),
            $sessions,
            $carriedSession,
            $services->logger,
        );

        return [
            new Route('GET', '/health', $health),
            new Route('HEAD', '/health', $health),
            new Route('POST', '/auth/register', $register),
            new Route('POST', '/auth/login', $login),
        ];
    }
}
