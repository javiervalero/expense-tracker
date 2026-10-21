<?php

declare(strict_types=1);

namespace App\Auth\Session;

use LogicException;

/** The outcome of {@see SessionAuthenticator}: exactly one of a Principal or an AnonymousReason. */
final readonly class Authentication
{
    private function __construct(
        public ?Principal $principal,
        public ?AnonymousReason $reason,
    ) {}

    public static function authenticated(Principal $principal): self
    {
        return new self($principal, null);
    }

    public static function anonymous(AnonymousReason $reason): self
    {
        return new self(null, $reason);
    }

    /** True when the request carried a session cookie that should now be cleared in the browser. */
    public function cookieWasSent(): bool
    {
        return match ($this->reason) {
            null => throw new LogicException('An authenticated request has no stale cookie.'),
            AnonymousReason::NoCookie => false,
            AnonymousReason::MalformedCookie,
            AnonymousReason::DuplicateCookie,
            AnonymousReason::UnknownSession,
            AnonymousReason::ExpiredSession,
            AnonymousReason::SessionEnded => true,
        };
    }
}
