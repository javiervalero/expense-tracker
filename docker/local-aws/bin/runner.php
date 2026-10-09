<?php

declare(strict_types=1);

/*
 * The worker runner (service `worker-runner` in docker-compose.yml): stands in for the Lambda SQS event source mapping.
 * It long-polls the receipts queue, posts each batch to the worker (the Lambda Runtime Interface Emulator) as an
 * AWS-shaped SQS event and deletes only the messages that succeeded (see src/Runner/Runner.php).
 *
 * SIGTERM / SIGINT (docker compose stop, Ctrl+C): the batch in hand is finished, then the process exits with code 0.
 * While it only waits for messages (a long poll lasts up to 20 s, Docker waits 10 s), it exits at once instead.
 * Exit code 1: misconfigured (nothing is polled).
 */

use LocalAws\Logging\JsonLogger;
use LocalAws\Runner\BatchResultInterpreter;
use LocalAws\Runner\QueueClientFactory;
use LocalAws\Runner\RieHttpInvoker;
use LocalAws\Runner\Runner;
use LocalAws\Runner\RunnerSettings;
use LocalAws\Runner\SqsEventBuilder;
use LocalAws\Runner\SqsQueueClient;
use LocalAws\Runner\StopSignal;

require __DIR__ . '/../vendor/autoload.php';

$logger = new JsonLogger(
    static function (string $line): void {
        fwrite(STDOUT, $line);
    },
    null,
    array_filter([getenv('AWS_SECRET_ACCESS_KEY')], is_string(...)),
);

if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
    $logger->error('runner cannot start: the pcntl extension is missing (it is installed by docker/local-aws/Dockerfile)');
    exit(1);
}

try {
    $settings = RunnerSettings::fromEnvironment(array_filter(getenv(), is_string(...)));
} catch (RuntimeException $failure) {
    $logger->error('runner misconfigured', ['error' => $failure->getMessage()]);
    exit(1);
}

$stop = new StopSignal();
pcntl_async_signals(true);
$onStop = static function (int $signal) use ($stop, $logger): void {
    $idle = $stop->request();
    $logger->info('stop requested', ['signal' => $signal, 'exitingNow' => $idle]);
    if ($idle) {
        exit(0);
    }
};
pcntl_signal(SIGTERM, $onStop);
pcntl_signal(SIGINT, $onStop);

$logger->info('runner started', [
    'queueUrl' => $settings->queueUrl,
    'invocationUrl' => $settings->invocationUrl,
    'eventSourceArn' => $settings->eventSourceArn,
]);

(new Runner(
    new SqsQueueClient(QueueClientFactory::create($settings), $settings->queueUrl),
    RieHttpInvoker::create($settings->invocationUrl),
    new SqsEventBuilder($settings->region, $settings->eventSourceArn),
    new BatchResultInterpreter(),
    $logger,
    $stop,
    static function (int $seconds): void {
        sleep($seconds);
    },
))->run();
