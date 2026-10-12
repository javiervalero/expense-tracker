<?php

declare(strict_types=1);

/*
 * Router script of the S3 events relay (service `s3-events-relay` in docker-compose.yml):
 *
 *     php -S 0.0.0.0:8080 bin/relay.php
 *
 * The S3 emulator POSTs its "object created" events to http://s3-events-relay:8080/ with a bearer token; the relay
 * puts them on the receipts queue as AWS-shaped messages (see src/Relay/RelayHandler.php for the contract).
 * Container port only, never published to the host. `GET /health` is the container healthcheck.
 * PHP runs this script once per request, so nothing is kept between requests.
 */

use LocalAws\Logging\JsonLogger;
use LocalAws\Relay\RelayHandler;
use LocalAws\Relay\RequestPath;
use LocalAws\Relay\RelayRouter;
use LocalAws\Relay\RelaySettings;
use LocalAws\Relay\SqsClientFactory;
use LocalAws\Relay\SqsMessageSender;

require __DIR__ . '/../vendor/autoload.php';

/** Same as the SQS message size limit (256 KiB): a bigger event could not be queued anyway. */
const MAX_BODY_BYTES = 262144;

// The STDOUT constant does not exist under `php -S` (the cli-server SAPI), so the stream is opened by name.
$stdout = fopen('php://stdout', 'wb');
$logger = new JsonLogger(
    static function (string $line) use ($stdout): void {
        if ($stdout !== false) {
            fwrite($stdout, $line);
        }
    },
    null,
    array_values(array_filter([getenv('AWS_SECRET_ACCESS_KEY'), getenv('S3_WEBHOOK_AUTH_TOKEN')], is_string(...))),
);

try {
    $settings = RelaySettings::fromEnvironment(array_filter(getenv(), is_string(...)));
} catch (RuntimeException $failure) {
    // Misconfigured: every request, the healthcheck included, fails, so `make up` reports the service unhealthy.
    $logger->error('relay misconfigured', ['error' => $failure->getMessage()]);
    http_response_code(500);

    return true;
}

$router = new RelayRouter(new RelayHandler(
    new SqsMessageSender(SqsClientFactory::create($settings), $settings->queueUrl),
    $logger,
    $settings->authToken,
    MAX_BODY_BYTES,
));

$headers = [];
foreach (getallheaders() as $name => $value) {
    if (is_string($value)) {
        $headers[(string) $name] = $value;
    }
}

// The raw path (see RequestPath): `//x` is not the root. The body is read lazily, after the method, the token and
// the declared Content-Length have been checked. One byte more than the cap is enough for the handler to answer 413
// without pulling a huge chunked body into memory (second layer; the first is the Content-Length check).
$readBody = static function (): string {
    $body = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);

    return $body === false ? '' : $body;
};

$response = $router->route(
    is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '',
    RequestPath::of(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/'),
    $headers,
    $readBody,
);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;

return true;
