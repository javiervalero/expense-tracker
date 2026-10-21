<?php

declare(strict_types=1);

// Child process of ThrottleCounterConcurrencyTest: `php throttle-hit.php <table> <ip> <limit> <index>`.
// Builds the real ThrottleCounter on its own DynamoDB client, reports "ready", blocks until the parent writes a line
// to stdin (the barrier), makes ONE hit() and prints "allowed|blocked <startNs> <endNs>" (hrtime, monotonic and shared by
// all processes of the host, so the parent can check that the hits overlapped). Before "ready" one hit() on a key of its
// own (198.51.100.<100 + index>, never the race key) pays for autoload, connection set-up and signing.

use App\Auth\AuthKeys;
use App\Auth\Throttle\ThrottleCounter;
use App\Auth\Throttle\ThrottleRule;
use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\LocalAws;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
$table = $arguments[1] ?? null;
$ip = $arguments[2] ?? null;
$limit = $arguments[3] ?? null;
$index = $arguments[4] ?? null;
if (!is_string($table) || !is_string($ip) || !is_string($limit) || !is_string($index)) {
    fwrite(STDERR, "usage: throttle-hit.php <table> <ip> <limit> <index>\n");
    exit(2);
}

$counter = new ThrottleCounter(new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $table), new SystemClock());
$rule = new ThrottleRule(AuthKeys::LOGIN_IP, (int) $limit, ThrottleRule::LOGIN_WINDOW_SECONDS);

$counter->hit($rule, '198.51.100.' . (100 + (int) $index));

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (fgets(STDIN) === false) {
    fwrite(STDERR, "stdin closed before the go signal\n");
    exit(3);
}

$start = hrtime(true);
$allowed = $counter->hit($rule, $ip)->decision->isAllowed;
$end = hrtime(true);
fwrite(STDOUT, sprintf("%s %d %d\n", $allowed ? 'allowed' : 'blocked', $start, $end));
