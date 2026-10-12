<?php

declare(strict_types=1);

namespace LocalAws\Runner;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;

/**
 * Invokes the worker through the Lambda Runtime Interface Emulator: POST <event JSON> to
 * http://worker:8080/2015-03-31/functions/function/invocations.
 *
 * Any HTTP answer is returned as it is (status and body); {@see BatchResultInterpreter} decides what it means.
 * The timeout is BELOW the queue's visibility timeout (10 s in docker/elasticmq/elasticmq.conf): after it, the batch
 * counts as failed and the messages come back to the queue only once their visibility has expired, so a slow worker
 * can never be running on messages that another poll is already handing out again.
 */
final readonly class RieHttpInvoker implements LambdaInvoker
{
    public const TIMEOUT_SECONDS = 8;
    private const CONNECT_TIMEOUT_SECONDS = 1;

    public function __construct(private ClientInterface $http, private string $url) {}

    /**
     * @param HandlerStack<callable(\Psr\Http\Message\RequestInterface, array<mixed>): \GuzzleHttp\Promise\PromiseInterface<\Psr\Http\Message\ResponseInterface, mixed>>|null $handler replaces the network layer (tests only)
     */
    public static function create(string $url, ?HandlerStack $handler = null): self
    {
        $config = ['connect_timeout' => self::CONNECT_TIMEOUT_SECONDS, 'timeout' => self::TIMEOUT_SECONDS, 'http_errors' => false];
        if ($handler !== null) {
            $config['handler'] = $handler;
        }

        return new self(new Client($config), $url);
    }

    public function invoke(string $eventJson): InvocationResult
    {
        try {
            $response = $this->http->request('POST', $this->url, ['body' => $eventJson]);
        } catch (GuzzleException $failure) {
            throw new InvocationFailed('invoking the worker failed: ' . $failure->getMessage(), 0, $failure);
        }

        return new InvocationResult($response->getStatusCode(), (string) $response->getBody());
    }
}
