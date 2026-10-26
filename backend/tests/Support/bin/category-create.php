<?php

declare(strict_types=1);

// Child process of CategoryNameLockConcurrencyTest: `php category-create.php <table> <userId> <name> <index>`.
// Builds the real CategoryRepository on its own DynamoDB client, reports "ready", blocks until the parent writes a
// line to stdin (the barrier), makes ONE create() of a category with a new random id and prints
// "created|taken|conflict <categoryId> <startNs> <endNs>" (`conflict`: the transaction was cancelled with a
// TransactionConflict, which production maps to 503; any other cancellation ends the child with an error) (hrtime, monotonic and shared by all processes of the host, so the
// parent can check that the creates overlapped). Before "ready" one create() for a user of its own (a random id, never
// the race user) pays for autoload, connection set-up and signing.

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryName;
use App\Categories\CategoryNameTaken;
use App\Categories\CategoryRepository;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\Id\RandomUuidGenerator;
use App\Tests\Support\LocalAws;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
$table = $arguments[1] ?? null;
$userId = $arguments[2] ?? null;
$text = $arguments[3] ?? null;
$index = $arguments[4] ?? null;
if (!is_string($table) || !is_string($userId) || !is_string($text) || !is_string($index)) {
    fwrite(STDERR, "usage: category-create.php <table> <userId> <name> <index>\n");
    exit(2);
}
$name = CategoryName::parse($text);
if (!$name instanceof CategoryName) {
    fwrite(STDERR, "the name is not a valid category name\n");
    exit(2);
}

$ids = new RandomUuidGenerator();
$categories = new CategoryRepository(new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $table));
$now = new DateTimeImmutable();

$categories->create(new Principal($ids->generate(), str_repeat('a', 64)), Category::new($ids->generate(), $name, $now));

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (fgets(STDIN) === false) {
    fwrite(STDERR, "stdin closed before the go signal\n");
    exit(3);
}

$categoryId = $ids->generate();
$start = hrtime(true);
try {
    $categories->create(new Principal($userId, str_repeat('a', 64)), Category::new($categoryId, $name, $now));
    $outcome = 'created';
} catch (CategoryNameTaken) {
    $outcome = 'taken';
} catch (TransactionCancelled $cancelled) {
    if (!in_array('TransactionConflict', $cancelled->reasons, true)) {
        throw $cancelled;
    }
    $outcome = 'conflict';
}
$end = hrtime(true);
fwrite(STDOUT, sprintf("%s %s %d %d\n", $outcome, $categoryId, $start, $end));
