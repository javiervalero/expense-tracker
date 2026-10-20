<?php

declare(strict_types=1);

namespace App\Auth\Session;

use App\Http\Request;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;

/**
 * Ends the session a request carried, if it carried one (A4): register and login always issue a fresh token, so the
 * old one must not outlive them. Only a well-formed cookie names a session; an absent, malformed or duplicated one
 * deletes nothing; a repeated one is logged as a warning (A20), with the request id and no values. A session that does not exist (already expired) is not an error.
 */
final readonly class CarriedSession
{
    public function __construct(private SessionStore $sessions, private JsonLogger $logger) {}

    /**
     * @throws StorageFailure
     */
    public function end(Request $request): void
    {
        $carried = SessionCookie::read($request);
        if ($carried->state === SessionCookieState::Duplicate) {
            // Same message as SessionAuthenticator. No values: two attacker-chosen cookies must not reach the log.
            $this->logger->warning('duplicate session cookie', ['requestId' => $request->attribute(Request::REQUEST_ID)]);
        }
        if ($carried->state === SessionCookieState::Present && $carried->token !== null) {
            $this->sessions->delete($carried->token->hash());
        }
    }
}
