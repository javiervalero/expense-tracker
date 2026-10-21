<?php

declare(strict_types=1);

namespace App\Auth\Session;

/** Why a request has no session. The client never sees it; it is for the code and the logs. */
enum AnonymousReason: string
{
    case NoCookie = 'no_cookie';
    case MalformedCookie = 'malformed_cookie';
    case DuplicateCookie = 'duplicate_cookie';
    case UnknownSession = 'unknown_session';
    case ExpiredSession = 'expired_session';
    /** The session was there when read but gone when renewed (logout, or TTL cleanup, in between). */
    case SessionEnded = 'ended';
}
