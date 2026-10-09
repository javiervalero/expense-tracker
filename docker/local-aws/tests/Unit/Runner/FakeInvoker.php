<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use LocalAws\Runner\InvocationResult;
use LocalAws\Runner\LambdaInvoker;

final class FakeInvoker implements LambdaInvoker
{
    /** @var list<string> */
    public array $payloads = [];
    /** @var (\Closure(): void)|null runs inside invoke(), like a signal arriving while the batch is processed */
    public ?\Closure $duringInvoke = null;

    public function __construct(private InvocationResult|\Throwable $answer) {}

    public function invoke(string $eventJson): InvocationResult
    {
        $this->payloads[] = $eventJson;
        if ($this->duringInvoke !== null) {
            ($this->duringInvoke)();
        }
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
