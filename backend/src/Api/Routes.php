<?php

declare(strict_types=1);

namespace App\Api;

use App\Health\HealthHandler;
use App\Http\Routing\Route;

/** The route table of the API (templates are relative to `/api/v1`). */
final class Routes
{
    /**
     * @return list<Route>
     */
    public static function all(): array
    {
        $health = new HealthHandler();

        return [
            new Route('GET', '/health', $health),
            new Route('HEAD', '/health', $health),
        ];
    }
}
