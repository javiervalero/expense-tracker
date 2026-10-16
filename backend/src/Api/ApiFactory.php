<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\ApiKernel;
use App\Http\Handler;
use App\Http\Middleware\ClientIpMiddleware;
use App\Http\Middleware\DeferredProblemMiddleware;
use App\Http\Middleware\ErrorHandlingMiddleware;
use App\Http\Middleware\OriginVerifyMiddleware;
use App\Http\Middleware\RequestLoggingMiddleware;
use App\Http\Middleware\StorageUnavailableMiddleware;
use App\Http\Pipeline;
use App\Http\Problem\ProblemDetails;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\Route;
use App\Http\Routing\RouteDispatcher;
use App\Http\Routing\Router;
use App\Infrastructure\Config\ApiConfig;
use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\Config\InvalidConfiguration;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Id\RandomUuidGenerator;
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
     * @param list<Route>|null $routes the route table; {@see Routes::all()} when omitted (a seam for tests)
     */
    public static function fromConfigLoader(Closure $loadConfig, mixed $logStream = null, ?array $routes = null): ApiKernel
    {
        $logStream ??= JsonLogger::stderrStream();
        // Until the configuration is valid there are no secrets to hide, and the line is about variable names only.
        $logger = new JsonLogger($logStream);
        try {
            $config = $loadConfig();
            // The request-path logger is built from the VALIDATED config: the origin secrets are on its redaction list.
            $requestLogger = new JsonLogger($logStream, null, $config->originVerifySecrets);

            // Built once per process: a warm Lambda reuses the client and the store for every invocation.
            $services = new ApiServices(
                new ItemStore(DynamoDbClientFactory::create($config->dynamoDb), $config->dynamoDb->tableName),
                new SystemClock(),
                new RandomUuidGenerator(),
                $requestLogger,
            );

            // Order, outermost first: errors are rendered as problem+json; the request line is written for every request,
            // a 403 included; the origin check runs before routing, so no path is exempt (unknown ones get 403, not
            // 404). A request that could not be built (oversized body, malformed query) travels as a bare request and
            // its problem is raised right after the origin check. ClientIp comes after that, so only a request that
            // passed the origin check has its address read. StorageUnavailable comes after ClientIp, right before the router:
            // DynamoDB overload thrown by a handler becomes a 503 there, and anything else travels up unchanged. The
            // router comes last. Any middleware that reads the
            // query or the body must come after DeferredProblemMiddleware.
            return new ApiKernel(new Pipeline(
                [
                    new ErrorHandlingMiddleware($requestLogger),
                    new RequestLoggingMiddleware($requestLogger),
                    new OriginVerifyMiddleware($requestLogger, $config->originVerifySecrets),
                    new DeferredProblemMiddleware(),
                    new ClientIpMiddleware($requestLogger, $config->clientIpHeader),
                    new StorageUnavailableMiddleware($requestLogger),
                ],
                new RouteDispatcher(new Router($routes ?? Routes::all($services))),
            ), null, $requestLogger);
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
