<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 (C10): `AuthKeys::receiptOwnerPartition(string)` builds a partition from a bare user id, with no session
 * `Principal`. It exists for the receipt worker's read and settle path only, so it may be called only from the file below.
 * Every API path builds its partition with `AuthKeys::userPartitionOf($owner)`.
 *
 * It tokenises with PhpToken: a call (`::`, `->` or `?->`) of `receiptOwnerPartition` in any case. Comments and strings
 * are ignored; the definition in Auth/AuthKeys.php is not a call. A string literal that is a guarded name, bare or after `::` (`[$o, 'name']`, `'Cls::name'`, `$o->{'name'}()`), is flagged too. Not caught: a name built at run time; review catches that.
 */
final class ReceiptOwnerPartitionCallTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/receiptownerpartition';

    /**
     * Relative to src/. ReceiptRepository is the one place that reads a receipt record by an object key (the worker's
     * `findByKey`, `markReady`, `markFailed`) and validates that the stored item belongs to that key's user. Adding a
     * file here needs a reason in this comment.
     */
    private const ALLOWED = ['Receipts/ReceiptRepository.php'];

    public function testCleanFixtureHasNoCall(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':14: call of receiptOwnerPartition',
            $file . ':15: call of RECEIPTOWNERPARTITION',
            $file . ':16: call of receiptOwnerPartition',
            $file . ':17: call of receiptOwnerPartition',
            $file . ":18: reference to 'receiptOwnerPartition'",
            $file . ":19: reference to 'receiptOwnerPartition'",
            $file . ":20: reference to 'AuthKeys::receiptOwnerPartition'",
            $file . ":21: reference to 'receiptOwnerPartition'",
            $file . ":22: call of receiptOwnerPartition",
            $file . ":23: call of receiptOwnerPartition",
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyTheListedFilesCallReceiptOwnerPartition(): void
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

    private static function namesGuarded(string $literal): bool
    {
        return preg_match('/(?:\A|::)receiptownerpartition\z/i', substr($literal, 1, -1)) === 1;
    }

    /**
     * @return list<string> "file:line: label" for each call, in source order
     */
    private function scanFile(string $path): array
    {
        $tokens = TokenScanner::tokens($path);
        $hits = [];
        foreach ($tokens as $index => $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING) && self::namesGuarded($token->text)) {
                $hits[] = "{$path}:{$token->line}: reference to {$token->text}";

                continue;
            }
            if (!$token->is(T_STRING) || strtolower($token->text) !== 'receiptownerpartition') {
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
