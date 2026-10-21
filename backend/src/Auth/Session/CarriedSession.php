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
            SessionCookie::warnDuplicate($this->logger, $request);
        }
        if ($carried->state === SessionCookieState::Present && $carried->token !== null) {
            $this->sessions->delete($carried->token->hash());
        }
    }

    /**
     * For a flow whose account and new session already exist (register, login): a storage failure must not turn the
     * answer into an error, so it is logged as a warning (request id, operation and AWS code, never a token) and the
     * old session simply expires on its own.
     */
    public function endBestEffort(Request $request): void
    {
        try {
            $this->end($request);
        } catch (StorageFailure $failure) {
            $this->logger->warning('could not end the carried session', [
                'requestId' => $request->attribute(Request::REQUEST_ID),
                'operation' => $failure->operation,
                'code' => $failure->awsErrorCode,
            ]);
        }
    }
}
