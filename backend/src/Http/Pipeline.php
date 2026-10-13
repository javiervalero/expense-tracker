<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Runs the middleware in order (the first one is the outermost) around a final handler. Immutable and
 * stateless, so one instance can serve many requests.
 */
final readonly class Pipeline implements Handler
{
    /**
     * @param list<Middleware> $middlewares
     */
    public function __construct(
        private array $middlewares,
        private Handler $final,
    ) {}

    public function handle(Request $request): Response
    {
        if ($this->middlewares === []) {
            return $this->final->handle($request);
        }

        return $this->middlewares[0]->process(
            $request,
            new self(array_slice($this->middlewares, 1), $this->final),
        );
    }
}
