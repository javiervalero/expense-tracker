<?php

declare(strict_types=1);

namespace App\Auth\Session;

/** How long a session lives (A3), in seconds. */
final class SessionLifetime
{
    /** A session ends after this long without a renewal. */
    public const int IDLE = 86_400;

    /**
     * A session ends this long after it was created, however often it was renewed. Lowering this shortens existing
     * sessions on their next read (the repository caps what is stored).
     */
    public const int ABSOLUTE = 604_800;

    /** The renewal is written at most this often. */
    public const int RENEW_AFTER = 900;
}
