<?php

declare(strict_types=1);

namespace App\Auth\Session;

/** What the request said about the session cookie (A20). */
enum SessionCookieState
{
    /** No `__Host-session` cookie. */
    case Absent;

    /** Exactly one cookie, but its value is not a well-formed token. */
    case Malformed;

    /** Two or more `__Host-session` cookies, whatever their values (cookie tossing). */
    case Duplicate;

    /** Exactly one well-formed cookie. */
    case Present;
}
