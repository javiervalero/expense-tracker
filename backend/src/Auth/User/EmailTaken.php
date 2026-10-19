<?php

declare(strict_types=1);

namespace App\Auth\User;

use RuntimeException;

/** Another user holds the e-mail lock. The message carries no address. */
final class EmailTaken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The e-mail address is already registered.');
    }
}
