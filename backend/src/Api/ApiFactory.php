<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\ApiKernel;
use App\Http\Handler;
use App\Http\Middleware\ErrorHandlingMiddleware;
use App\Http\Middleware\RequestLoggingMiddleware;
use App\Http\Pipeline;
use App\Http\Problem\ProblemDetails;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteDispatcher;
use App\Http\Routing\Router;
use App\Infrastructure\Config\ApiConfig;
use App\Infrastructure\Config\InvalidConfiguration;
use App\Infrastructure\Logging\JsonLogger;
use Closure;
use Throwable;

/**
 * Composition root of the API, like {@see \App\Worker\WorkerFactory} for the worker. Knows nothing about php-fpm or
 * Lambda: `public/index.php` and the Lambda entrypoint both call it. If the configuration is invalid, or anything else fails at
 * startup, the kernel is fail-closed: it answers a generic 500 to every request instead of serving with half a configuration.
 */
final class ApiFactory
{
    /**
     * @param array<array-key, mixed> $env
     * @param resource|null $logStream where the JSON log lines go; stderr when omitted
     */
    public static function fromEnvironment(array $env, mixed $logStream = null): ApiKernel
    {
        return self::fromConfigLoader(static fn(): ApiConfig => ApiConfig::fromEnvironment($env), $logStream);
    }

    /**
     * @param Closure(): ApiConfig $loadConfig
     * @param resource|null $logStream
     */
    public static function fromConfigLoader(Closure $loadConfig, mixed $logStream = null): ApiKernel
    {
        $logger = new JsonLogger($logStream ?? JsonLogger::stderrStream());
        try {
            $loadConfig();

            // Later work packages slot OriginVerify and ClientIp between the two (ADR 0012), and the router comes last.
            return new ApiKernel(new Pipeline(
                [new ErrorHandlingMiddleware($logger), new RequestLoggingMiddleware($logger)],
                new RouteDispatcher(new Router(Routes::all())),
            ), null, $logger);
        } catch (InvalidConfiguration $invalid) {
            $logger->error('invalid configuration, answering 500 to every request', ['errors' => $invalid->errors]);
        } catch (Throwable $error) {
            // Class name only: the message of an unexpected error may hold a secret.
            $logger->error('startup failed, answering 500 to every request', ['exception' => $error::class]);
        }

        return new ApiKernel(self::alwaysInternalError(), null, $logger);
    }

    private static function alwaysInternalError(): Handler
    {
        return new class implements Handler {
            public function handle(Request $request): Response
            {
                return Response::problem(ProblemDetails::forStatus(500));
            }
        };
    }
}
