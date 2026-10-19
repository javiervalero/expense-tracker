<?php

declare(strict_types=1);

namespace App\Auth\Session;

/** The result of {@see SessionCookie::read()}: a state, plus the token only when the state is Present. */
final readonly class SessionCookieRead
{
    // Invariant: the token is non-null if and only if the state is Present; the private constructor and the
    // factories below are the only way to build one.
    private function __construct(
        public SessionCookieState $state,
        public ?SessionToken $token = null,
    ) {}

    public static function absent(): self
    {
        return new self(SessionCookieState::Absent);
    }

    public static function malformed(): self
    {
        return new self(SessionCookieState::Malformed);
    }

    public static function duplicate(): self
    {
        return new self(SessionCookieState::Duplicate);
    }

    public static function present(SessionToken $token): self
    {
        return new self(SessionCookieState::Present, $token);
    }
}
