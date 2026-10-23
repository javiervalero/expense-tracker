<?php

declare(strict_types=1);

namespace App\Tests\Integration\Categories;

use App\Auth\Session\Principal;
use App\Categories\CategoryKeys;
use App\Categories\CategoryRepository;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use PHPUnit\Framework\TestCase;

/**
 * The real race proof for the category name lock (B6): many separate PHP processes, each with its own DynamoDB client
 * and the real CategoryRepository, wait on a barrier and then create a category with the same name (in four spellings
 * that differ only in case) for one user at the same moment. Exactly one may succeed, and the table must hold one
 * category and one lock, the winner's. A read-then-write check would let several through.
 *
 * DynamoDB Local serialises transactions, so here every loser reports `taken`; a `conflict` (TransactionConflict, which
 * production answers with 503, not 409) is accepted but this test does not prove that split. A loser whose transaction
 * is cancelled for another reason makes its child fail.
 *
 * Each child reports its create's start and end (hrtime), and the test checks that the creates were contended (a
 * refused create started before the winning one ended), so the exact counts are not a result of running serially.
 *
 * The children are `tests/Support/bin/category-create.php` (no pcntl in the image, so proc_open). Every child is
 * terminated and every pipe closed in `finally`, whatever happens.
 */
final class CategoryNameLockConcurrencyTest extends TestCase
{
    private const int PROCESSES = 10;
    private const string USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const array NAME_VARIANTS = ['Food', 'FOOD', 'food', 'fOOd'];
    private const int START_TIMEOUT_SECONDS = 60;
    private const int HIT_TIMEOUT_SECONDS = 30;

    private TemporaryTable $table;

    /** @var list<array{process: resource, stdin: resource, stdout: resource, stderr: resource}> */
    private array $children = [];

    protected function setUp(): void
    {
        $this->table = TemporaryTable::create(LocalAws::fromGlobals()->dynamoDb());
    }

    protected function tearDown(): void
    {
        $this->reap();
        $this->table->drop();
    }

    public function testParallelProcessesCreatingOneNameLeaveExactlyOneWinner(): void
    {
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $this->spawn();
        }
        $ready = $this->readLines(self::START_TIMEOUT_SECONDS);
        self::assertSame(array_fill(0, self::PROCESSES, 'ready'), $ready, 'every child is built and waiting');

        foreach ($this->children as $index => $child) {
            $written = @fwrite($child['stdin'], "go\n");
            if ($written !== 3 || !@fflush($child['stdin'])) {
                self::fail("cannot send the go signal to child $index (it has probably died)\n" . $this->stderrOfChildren());
            }
        }
        $results = $this->parseResults($this->readLines(self::HIT_TIMEOUT_SECONDS));

        $created = array_values(array_filter($results, static fn(array $r): bool => $r['outcome'] === 'created'));
        self::assertCount(1, $created, 'exactly one create wins');
        $others = array_values(array_filter($results, static fn(array $r): bool => $r['outcome'] !== 'created'));
        self::assertCount(self::PROCESSES - 1, $others, 'every other child is refused (taken) or hit a transaction conflict (conflict)');
        $store = new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $this->table->name);
        $owner = new Principal(self::USER, str_repeat('a', 64));
        $categories = (new CategoryRepository($store))->listAll($owner);
        self::assertCount(1, $categories);
        self::assertSame($created[0]['id'], $categories[0]->id);
        $locks = $store->query(
            '#pk = :pk AND begins_with(SK, :prefix)',
            ['#pk' => 'PK'],
            [':pk' => 'USER#' . self::USER, ':prefix' => CategoryKeys::NAME_LOCK_PREFIX],
        )->items;
        self::assertCount(1, $locks);
        self::assertSame($created[0]['id'], $locks[0]['categoryId']);
        // Weakest check that still proves the barrier does not serialise the creates: some refused create started
        // before the winning one ended. Run one after another, every refused create would start after the winner ended.
        $winnerEnd = $created[0]['end'];
        $firstRefusedStart = min(PHP_INT_MAX, ...array_column($others, 'start'));
        self::assertLessThan(
            $winnerEnd,
            $firstRefusedStart,
            sprintf('the creates were serialised: the first refused create started %.1f ms after the winner ended', ($firstRefusedStart - $winnerEnd) / 1_000_000),
        );
    }

    /**
     * @param list<string> $lines `created|taken|conflict <categoryId> <startNs> <endNs>`
     *
     * @return list<array{outcome: string, id: string, start: int, end: int}>
     */
    private function parseResults(array $lines): array
    {
        $results = [];
        foreach ($lines as $index => $line) {
            self::assertSame(1, preg_match('/\A(created|taken|conflict) ([0-9a-f-]{36}) (\d+) (\d+)\z/', $line, $match), "child $index answered '$line'\n" . $this->stderrOfChildren());
            $results[] = ['outcome' => $match[1], 'id' => $match[2], 'start' => (int) $match[3], 'end' => (int) $match[4]];
        }

        return $results;
    }

    private function spawn(): void
    {
        $script = dirname(__DIR__, 2) . '/Support/bin/category-create.php';
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $script, $this->table->name, self::USER, self::NAME_VARIANTS[count($this->children) % count(self::NAME_VARIANTS)], (string) count($this->children)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'cannot start a child process');
        $this->children[] = ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * One line from every child, in child order. Fails (with the stderr of the children) on a timeout or when a child
     * ends without a line.
     *
     * @return list<string>
     */
    private function readLines(int $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $buffers = array_fill(0, count($this->children), '');
        while (true) {
            $pending = [];
            foreach ($this->children as $index => $child) {
                if (!str_contains($buffers[$index], "\n")) {
                    $pending[$index] = $child['stdout'];
                }
            }
            if ($pending === []) {
                return array_map(static fn(string $buffer): string => explode("\n", $buffer, 2)[0], array_values($buffers));
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                self::fail("timed out after {$timeoutSeconds}s waiting for " . count($pending) . " child(ren)\n" . $this->stderrOfChildren());
            }
            $read = array_values($pending);
            $write = $except = null;
            $changed = stream_select($read, $write, $except, (int) floor($remaining), (int) (fmod($remaining, 1) * 1_000_000));
            if ($changed === false) {
                self::fail('stream_select failed');
            }
            foreach ($pending as $index => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    self::fail("child $index ended without answering\n" . $this->stderrOfChildren());
                }
                $buffers[$index] .= $chunk;
            }
        }
    }

    private function stderrOfChildren(): string
    {
        $text = '';
        foreach ($this->children as $index => $child) {
            stream_set_blocking($child['stderr'], false);
            $output = trim((string) stream_get_contents($child['stderr']));
            if ($output !== '') {
                $text .= "child $index stderr: $output\n";
            }
        }

        return $text === '' ? "(the children wrote nothing to stderr)\n" : $text;
    }

    /**
     * Closes every pipe and ends every child, also the ones still running, so nothing is left behind.
     */
    private function reap(): void
    {
        foreach ($this->children as $child) {
            foreach (['stdin', 'stdout', 'stderr'] as $pipe) {
                if (is_resource($child[$pipe])) {
                    fclose($child[$pipe]);
                }
            }
            $status = proc_get_status($child['process']);
            if ($status['running']) {
                proc_terminate($child['process']);
            }
            proc_close($child['process']);
        }
        $this->children = [];
    }
}
