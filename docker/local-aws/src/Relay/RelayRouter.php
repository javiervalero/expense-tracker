<?php

declare(strict_types=1);

namespace LocalAws\Relay;

/**
 * The relay's three routes: `GET /health` (no authentication, for the container healthcheck), `/` (the S3
 * emulator's webhook, handled by {@see RelayHandler}) and 404 for everything else.
 */
final readonly class RelayRouter
{
    public function __construct(private RelayHandler $handler) {}

    /**
     * @param array<string, string> $headers
     * @param string|\Closure(): string $body the body, or a reader called only when the body is needed
     */
    public function route(string $method, string $path, array $headers, string|\Closure $body): RelayResponse
    {
        if ($path === '/health' && $method === 'GET') {
            return new RelayResponse(200, '{"status":"ok"}', ['Content-Type' => 'application/json']);
        }
        if ($path !== '/') {
            return new RelayResponse(404);
        }

        return $this->handler->handle($method, $headers, $body);
    }
}
