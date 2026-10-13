<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * The API's configuration, read once from the process environment by the composition root. It grows with the
 * work packages that need settings (origin secret, rate limits, table names). Every problem is collected before
 * failing, and only variable names are reported, never values.
 */
final readonly class ApiConfig
{
    /** Variables the API cannot start without. None yet; later work packages add theirs here. */
    private const REQUIRED = [];

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $env
     *
     * @throws InvalidConfiguration
     */
    public static function fromEnvironment(array $env): self
    {
        return self::validate($env, self::REQUIRED);
    }

    /**
     * @param array<array-key, mixed> $env
     * @param list<string> $required names of the variables that must hold a non-empty string
     *
     * @throws InvalidConfiguration
     */
    public static function validate(array $env, array $required): self
    {
        $errors = [];
        foreach ($required as $name) {
            $problem = self::problemWith($env, $name);
            if ($problem !== null) {
                $errors[] = $name . ' ' . $problem;
            }
        }
        sort($errors);
        if ($errors !== []) {
            throw new InvalidConfiguration($errors);
        }

        return new self();
    }

    /**
     * @param array<array-key, mixed> $env
     */
    private static function problemWith(array $env, string $name): ?string
    {
        if (!array_key_exists($name, $env)) {
            return 'is missing';
        }
        if (!is_string($env[$name])) {
            return 'is not a string';
        }

        return $env[$name] === '' ? 'is empty' : null;
    }
}
