<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 (B10): the partition of a user's data comes from a session `Principal` through
 * `AuthKeys::userPartitionOf()`. The string form, `userPartition(string)`, may be called only from the files below.
 *
 * It tokenises with PhpToken: a call (`::`, `->` or `?->`) of `userPartition` in any case. Comments and strings are
 * ignored. Not caught: a dynamic method name; review catches that.
 */
final class UserPartitionCallTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/partitioncall';

    /**
     * Relative to src/. AuthKeys builds the partition. The Phase 2 login and registration code has no principal yet (the
     * user is not signed in), so it reaches the string form only through AuthKeys::user($userId); today it has no direct
     * caller, so nothing else is listed. Adding a file here needs a reason in this comment.
     */
    private const ALLOWED = ['Auth/AuthKeys.php'];

    public function testCleanFixtureHasNoCall(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':14: call of userPartition',
            $file . ':15: call of USERPARTITION',
            $file . ':16: call of userPartition',
            $file . ':17: call of userPartition',
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyTheListedFilesCallTheStringFormOfUserPartition(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $allowed = array_map(static fn(string $file): string => $root . '/' . $file, self::ALLOWED);
        foreach ($allowed as $file) {
            self::assertFileExists($file);
        }
        $hits = [];
        foreach (TokenScanner::phpFiles($root) as $path) {
            if (!in_array($path, $allowed, true)) {
                $hits = [...$hits, ...$this->scanFile($path)];
            }
        }

        self::assertSame([], $hits, 'Build a user partition from a Principal: AuthKeys::userPartitionOf($owner).');
    }

    /**
     * @return list<string> "file:line: label" for each call, in source order
     */
    private function scanFile(string $path): array
    {
        $tokens = TokenScanner::tokens($path);
        $hits = [];
        foreach ($tokens as $index => $token) {
            if (!$token->is(T_STRING) || strtolower($token->text) !== 'userpartition') {
                continue;
            }
            $isCall = TokenScanner::neighbour($tokens, $index, -1)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]) === true
                && TokenScanner::neighbour($tokens, $index, 1)?->text === '(';
            if ($isCall) {
                $hits[] = "{$path}:{$token->line}: call of {$token->text}";
            }
        }

        return $hits;
    }
}
