<?php

declare(strict_types=1);

namespace App\Auth\Http;

use App\Auth\Session\CarriedSession;
use App\Auth\Session\SessionCookie;
use App\Http\Handler;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DynamoDb\StorageFailure;

/**
 * `POST /auth/logout` (A5): deletes the session the request carries, if any, and always answers `204` with the
 * clearing cookie, so a repeat, an unknown cookie and no cookie at all look the same (no oracle). It is public: no
 * session is required, and the CSRF middleware has already refused a forged request. Unlike register and login, the
 * deletion is the whole point of the call, so a storage failure is NOT swallowed: the client must not be told it is
 * signed out while the session lives on (the error middleware answers 500 or 503).
 */
final readonly class LogoutHandler implements Handler
{
    public function __construct(private CarriedSession $carriedSession) {}

    /**
     * @throws StorageFailure
     */
    public function handle(Request $request): Response
    {
        $this->carriedSession->end($request);

        return Response::empty(204, ['Set-Cookie' => [SessionCookie::clear()]]);
    }
}
