<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

use RuntimeException;

/**
 * The environment does not hold a valid configuration. `errors` name the variables at fault and what is wrong
 * with them, never their values, so the message is safe to log.
 */
final class InvalidConfiguration extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid configuration: ' . implode('; ', $errors));
    }
}
