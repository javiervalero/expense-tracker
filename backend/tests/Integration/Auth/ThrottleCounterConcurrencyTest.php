<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\AuthKeys;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use PHPUnit\Framework\TestCase;

/**
 * The real race proof for the throttle counter (A15, increment first): many separate PHP processes, each with its own
 * DynamoDB client and the real ThrottleCounter, wait on a barrier and then call hit() on the same bucket at the same
 * moment. Exactly `limit` of them may be allowed and the stored count must be the number of processes. A counter that
 * reads, compares and then writes would let several through at the boundary.
 *
 * Each child reports its hit's start and end (hrtime), and the test checks that the limit boundary was contended
 * (a blocked hit started before the last allowed one ended), so the exact counts are not a result of running serially.
 *
 * The children are `tests/Support/bin/throttle-hit.php` (no pcntl in the image, so proc_open). Every child is
 * terminated and every pipe closed in `finally`, whatever happens.
 */
final class ThrottleCounterConcurrencyTest extends TestCase
{
    private const int PROCESSES = 25;
    private const int LIMIT = 10;
    private const string IP = '198.51.100.9';
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

    public function testParallelProcessesGetDistinctCountsSoExactlyTheLimitIsAllowed(): void
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

        $counts = array_count_values(array_column($results, 'decision'));
        ksort($counts);
        self::assertSame(['allowed' => self::LIMIT, 'blocked' => self::PROCESSES - self::LIMIT], $counts);
        $item = (new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $this->table->name))->getItem(AuthKeys::throttle(AuthKeys::LOGIN_IP, self::IP));
        self::assertNotNull($item);
        self::assertSame(self::PROCESSES, $item['attempts'], 'every attempt is counted, also the blocked ones');
        // Weakest check that still proves the barrier does not serialise the hits: some blocked hit started before the
        // last allowed hit ended, i.e. the limit boundary was contended. Run one after another, every blocked hit would
        // start after all allowed ones ended. A strict "all 25 overlap" flaked on a 2-core CI runner (spread of ~16 ms).
        $allowedEnds = array_column(array_filter($results, static fn(array $r): bool => $r['decision'] === 'allowed'), 'end');
        $blockedStarts = array_column(array_filter($results, static fn(array $r): bool => $r['decision'] === 'blocked'), 'start');
        $lastAllowedEnd = max(PHP_INT_MIN, ...$allowedEnds);
        $firstBlockedStart = min(PHP_INT_MAX, ...$blockedStarts);
        self::assertLessThan(
            $lastAllowedEnd,
            $firstBlockedStart,
            sprintf('the hits were serialised: the first blocked hit started %.1f ms after the last allowed hit ended', ($firstBlockedStart - $lastAllowedEnd) / 1_000_000),
        );
    }

    /**
     * @param list<string> $lines `allowed|blocked <startNs> <endNs>`
     *
     * @return list<array{decision: string, start: int, end: int}>
     */
    private function parseResults(array $lines): array
    {
        $results = [];
        foreach ($lines as $index => $line) {
            self::assertSame(1, preg_match('/\A(allowed|blocked) (\d+) (\d+)\z/', $line, $match), "child $index answered '$line'\n" . $this->stderrOfChildren());
            $results[] = ['decision' => $match[1], 'start' => (int) $match[2], 'end' => (int) $match[3]];
        }

        return $results;
    }

    private function spawn(): void
    {
        $script = dirname(__DIR__, 2) . '/Support/bin/throttle-hit.php';
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $script, $this->table->name, self::IP, (string) self::LIMIT, (string) count($this->children)],
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
