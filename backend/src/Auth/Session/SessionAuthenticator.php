<?php

declare(strict_types=1);

namespace App\Auth\Session;

use App\Http\Request;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;

/**
 * Request to {@see Authentication}. A cookie that is absent, malformed or duplicated (A20) is anonymous without any
 * storage read. Expiry is checked here as well as in the store, because the TTL deletes late. A renewal that fails
 * for transient storage reasons is logged and the request still authenticates; a renewal that finds the session gone does not.
 * A storage failure on the read, or a corrupt item found by the renewal, is not caught: the error middleware turns it
 * into a 500 or 503.
 */
final readonly class SessionAuthenticator
{
    public function __construct(
        private SessionStore $sessions,
        private Clock $clock,
        private JsonLogger $logger,
    ) {}

    /**
     * @throws StorageFailure
     */
    public function authenticate(Request $request): Authentication
    {
        $cookie = SessionCookie::read($request);
        if ($cookie->token === null) {
            return $this->withoutToken($cookie->state, $request);
        }
        $now = $this->clock->now()->getTimestamp();
        $session = $this->sessions->find($cookie->token->hash(), $now);
        if ($session === null) {
            return Authentication::anonymous(AnonymousReason::UnknownSession);
        }
        // Defence in depth: the store already filters expired sessions, but this check costs nothing and keeps the
        // rule from depending on which SessionStore is behind it.
        if (!$session->isActiveAt($now)) {
            return Authentication::anonymous(AnonymousReason::ExpiredSession);
        }
        if ($session->dueForRenewal($now) && !$this->renewed($session, $now, $request)) {
            return Authentication::anonymous(AnonymousReason::SessionEnded);
        }

        return Authentication::authenticated(new Principal($session->userId, $session->tokenHash));
    }

    private function withoutToken(SessionCookieState $state, Request $request): Authentication
    {
        if ($state === SessionCookieState::Duplicate) {
            // No values: two attacker-chosen cookies must not reach the log.
            $this->logger->warning('duplicate session cookie', ['requestId' => $request->attribute(Request::REQUEST_ID)]);
        }

        return Authentication::anonymous(match ($state) {
            SessionCookieState::Duplicate => AnonymousReason::DuplicateCookie,
            SessionCookieState::Malformed => AnonymousReason::MalformedCookie,
            SessionCookieState::Absent => AnonymousReason::NoCookie,
            SessionCookieState::Present => throw new \LogicException('A present cookie has a token.'),
        });
    }

    /**
     * @return bool false when the session turned out to be gone
     */
    private function renewed(Session $session, int $now, Request $request): bool
    {
        try {
            $this->sessions->renew($session, $now);
        } catch (ConditionFailed) {
            return false;
        } catch (StorageFailure $failure) {
            if ($failure->awsErrorCode === SessionStore::CORRUPT_ITEM) {
                throw $failure; // A bad item is a bug or tampering, not a hiccup: surface it as a 500.
            }
            $this->logger->warning('session renewal failed', [
                'requestId' => $request->attribute(Request::REQUEST_ID),
                'operation' => $failure->operation,
                'code' => $failure->awsErrorCode,
            ]);
        }

        return true;
    }
}
