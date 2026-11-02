<?php

declare(strict_types=1);

namespace App\Tests\Support\S3;

use App\Infrastructure\S3\S3ClientFactory;
use App\Infrastructure\S3\S3Settings;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;

/**
 * An S3 client that never touches a network: it answers from a queue (a Result, or a Throwable to raise) and records
 * every call, so a test can see which operations were sent and with what arguments.
 */
final class RecordingS3
{
    /** @var list<array{operation: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<Result<string, mixed>|\Throwable> */
    private array $queue;

    /**
     * @param list<Result<string, mixed>|\Throwable> $answers
     */
    public function __construct(array $answers = [])
    {
        $this->queue = $answers;
    }

    public function client(): S3Client
    {
        $handler = function (CommandInterface $command, RequestInterface $request): mixed {
            $args = [];
            foreach ($command->toArray() as $name => $value) {
                $args[(string) $name] = $value;
            }
            $this->calls[] = ['operation' => $command->getName(), 'args' => $args];
            $next = array_shift($this->queue) ?? new Result([]);

            return $next instanceof \Throwable ? Create::rejectionFor($next) : Create::promiseFor($next);
        };

        return S3ClientFactory::forCalls(
            S3Settings::fromEnvironment(['RECEIPTS_BUCKET' => 'receipts-bucket', 'THUMBNAILS_BUCKET' => 'thumbs-bucket', 'AWS_REGION' => 'eu-west-1']),
            $handler,
        );
    }

    /**
     * @return list<string>
     */
    public function operations(): array
    {
        return array_map(static fn(array $call): string => $call['operation'], $this->calls);
    }
}
