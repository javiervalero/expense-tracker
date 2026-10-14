<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\ApiKernel;
use App\Http\JsonBody;
use App\Http\ResponseEmitter;
use ErrorException;
use Throwable;

/**
 * What `public/index.php` does under php-fpm, as a tested class: read the body (capped at the JSON limit + 1 byte,
 * so an oversized body is detectable but never held in full), build the kernel, run the request, emit the response.
 *
 * Fails closed: ANY Throwable, including one from the startup or from reading the body, ends as the constant
 * generic 500 (no message, nothing from the error) with the kernel's default headers. PHP errors and warnings
 * become exceptions meanwhile, except the ones silenced with `@` (such as the logger's `@fwrite`).
 */
final class FrontController
{
    /**
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $env
     * @param resource|null $logStream where the JSON log lines go; stderr when omitted
     */
    public static function serve(
        array $server,
        array $env,
        ResponseEmitter $emitter,
        string $input = 'php://input',
        mixed $logStream = null,
    ): void {
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($method) ? $method : 'GET';

        set_error_handler([self::class, 'handleError']);
        try {
            $body = file_get_contents($input, false, null, 0, JsonBody::MAX_BYTES + 1);
            $response = ApiFactory::fromEnvironment($env, $logStream)->handleGlobals($server, $body === false ? '' : $body);
        } catch (Throwable) {
            $response = ApiKernel::internalErrorResponse();
        } finally {
            restore_error_handler();
        }

        $emitter->emit($response, $method);
    }

    /**
     * @throws ErrorException
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }
}
