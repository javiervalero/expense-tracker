<?php

declare(strict_types=1);

use App\Worker\WorkerFactory;
use App\Worker\WorkerRuntime;

// Lambda handler file (Bref function runtime): returns the closure that is called once per invocation.
// A bad configuration stops the start here (the error names variables, never values).
WorkerRuntime::configure();
$worker = WorkerFactory::create(getenv());

return static fn(array $event): array => $worker->handle($event);
