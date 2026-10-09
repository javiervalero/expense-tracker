<?php

declare(strict_types=1);

use App\Worker\WorkerFactory;

// Lambda handler file (Bref function runtime): returns the closure that is called once per invocation.
$worker = WorkerFactory::create();

return static fn(array $event): array => $worker->handle($event);
