<?php

declare(strict_types=1);

namespace App\Auth\Http;

use App\Auth\Email;
use App\Http\Response;

/**
 * The answer that signs a user in, shared by register (`201`) and login (`200`) so the two bodies and the cookie
 * header cannot drift: `{id, email}` (the contract's `User`) and the session cookie. Never the password or a hash.
 */
final class UserResponse
{
    public static function signedIn(int $status, string $userId, Email $email, string $sessionCookie): Response
    {
        return Response::json($status, ['id' => $userId, 'email' => $email->value()], ['Set-Cookie' => [$sessionCookie]]);
    }
}
