<?php

declare(strict_types=1);

namespace LocalAws\Runner;

interface LambdaInvoker
{
    /**
     * @throws InvocationFailed when there is no answer (transport error or timeout); any HTTP answer is returned
     */
    public function invoke(string $eventJson): InvocationResult;
}
