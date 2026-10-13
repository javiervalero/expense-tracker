<?php

declare(strict_types=1);

namespace App\Health;

use App\Http\Handler;
use App\Http\Request;
use App\Http\Response;

/** Liveness: the process runs and the front controller works. It touches no dependency. */
final class HealthHandler implements Handler
{
    public function handle(Request $request): Response
    {
        return Response::json(200, ['status' => 'ok']);
    }
}
