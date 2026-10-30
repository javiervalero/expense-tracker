<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * The `APP_ENV=local` rules shared by the API and the worker for the endpoint overrides (the in-app twin of
 * `make check-env`):
 *
 * - in a local environment every endpoint is mandatory, so an empty value can never silently mean real AWS;
 * - in any other environment (also when `APP_ENV` is unset) an endpoint must not be set at all, so a stand-in address
 *   can never reach a deployment by accident.
 *
 * The addresses themselves are checked by the settings classes ({@see LocalEnvironment::ENDPOINTS} names them once).
 */
final class LocalEnvironment
{
    /** Every variable that can point the AWS clients at a local stand-in. */
    public const array ENDPOINTS = ['DYNAMODB_ENDPOINT', 'S3_ENDPOINT', 'S3_PUBLIC_ENDPOINT'];

    private const APP_ENV = 'APP_ENV';
    private const LOCAL_ENV = 'local';

    private function __construct() {}

    /**
     * Adds one error per endpoint variable that breaks the rules above; the errors name variables, never values.
     *
     * @param array<array-key, mixed> $env
     * @param list<string> $errors
     * @param-out list<string> $errors
     */
    public static function checkEndpoints(array $env, array &$errors): void
    {
        $appEnv = $env[self::APP_ENV] ?? '';
        $isLocal = is_string($appEnv) && strtolower(trim($appEnv)) === self::LOCAL_ENV;
        foreach (self::ENDPOINTS as $name) {
            $value = $env[$name] ?? null;
            $isEmpty = $value === null || (is_string($value) && trim($value) === '');
            if ($isLocal && $isEmpty) {
                $errors[] = $name . ' is required when ' . self::APP_ENV . ' is ' . self::LOCAL_ENV;
            } elseif (!$isLocal && !$isEmpty) {
                $errors[] = $name . ' is set but ' . self::APP_ENV . ' is not ' . self::LOCAL_ENV;
            }
        }
    }
}
