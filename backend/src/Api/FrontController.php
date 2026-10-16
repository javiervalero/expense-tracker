<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\ApiKernel;
use App\Http\JsonBody;
use App\Http\ResponseEmitter;
use App\Infrastructure\Logging\JsonLogger;
use ErrorException;
use Throwable;

/**
 * What `public/index.php` does under php-fpm, as a tested class: read the body (capped at the JSON limit + 1 byte,
 * so an oversized body is detectable but never held in full), build the kernel, run the request, emit the response.
 *
 * Fails closed: ANY Throwable, including one from the startup or from reading the body, ends as the constant
 * generic 500 (no message, nothing from the error) with the kernel's default headers. PHP errors and warnings
 * become exceptions meanwhile, except the ones silenced with `@` (such as the logger's `@fwrite`). That is
 * deliberate and fail closed: every notice, warning and deprecation that `error_reporting` lets through ends
 * the request as a 500, rather than being served with something unexpected going on.
 *
 * The catch-all writes one fixed line with the exception class only (no message, file or trace, which could hold
 * secrets or user data) to the log stream.
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
        } catch (Throwable $error) {
            self::logFailure($error, $logStream);
            $response = ApiKernel::internalErrorResponse();
        } finally {
            restore_error_handler();
        }

        $emitter->emit($response, $method);
    }

    /**
     * @param resource|null $logStream
     */
    private static function logFailure(Throwable $error, mixed $logStream): void
    {
        try {
            $stream = $logStream ?? JsonLogger::stderrStream();
            @fwrite($stream, json_encode([
                'level' => 'error',
                'message' => 'front controller failed',
                'exception' => $error::class,
            ]) . "\n");
            // @codeCoverageIgnoreStart
            // Unreachable in tests (a fixed array, and stderr always opens); this only keeps "fail closed" true.
        } catch (Throwable) {
            // A broken log must not break the answer.
        }
        // @codeCoverageIgnoreEnd
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
