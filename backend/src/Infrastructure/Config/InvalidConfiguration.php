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

    /**
     * Reads one group of settings: its value, or null with its errors added to `$errors` so the caller can report every
     * problem of every group at once.
     *
     * @template T of object
     *
     * @param \Closure(): T $read
     * @param list<string> $errors
     * @param-out list<string> $errors
     *
     * @return T|null
     */
    public static function collect(\Closure $read, array &$errors): ?object
    {
        try {
            return $read();
        } catch (InvalidConfiguration $invalid) {
            array_push($errors, ...$invalid->errors);

            return null;
        }
    }
}
