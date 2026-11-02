<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 (C10): `ReceiptRepository::findByKey()`, `markReady()`, `markFailed()` and `markReplaced()` act on a record from an object key
 * alone, with no session. They are for the receipt worker, so they may be called only from files under src/Worker/.
 *
 * It tokenises with PhpToken: a call (`::`, `->` or `?->`) of one of the three names in any case. Comments and strings
 * are ignored; a definition is not a call. The match is by method name only: no other class in src/ has a method with
 * one of these names today, so there are no false positives; a future homonym would have to be renamed or listed here.
 * A string literal that is a guarded name, bare or after `::` (`[$o, 'name']`, `'Cls::name'`, `$o->{'name'}()`), is flagged too. Not caught: a name built at run time; review catches that.
 */
final class ReceiptWorkerCallTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/receiptworkercall';

    private const METHODS = ['findbykey', 'markready', 'markfailed', 'markreplaced'];

    /**
     * Relative to src/, a directory prefix. Only the worker settles an upload: `Receipts/Processing/ReceiptProcessor`
     * (WP8) is the worker's per-record code and lives beside the file inspection, so that directory is the worker's too.
     * Adding an entry here needs a reason in this comment.
     */
    private const ALLOWED_DIRECTORIES = ['Worker/', 'Receipts/Processing/'];

    public function testCleanFixtureHasNoCall(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':12: call of findByKey',
            $file . ':13: call of MARKREADY',
            $file . ':14: call of markFailed',
            $file . ':15: call of findByKey',
            $file . ":16: reference to 'findByKey'",
            $file . ":17: reference to 'markReady'",
            $file . ":18: reference to 'Receipts::markFailed'",
            $file . ":19: reference to 'findByKey'",
            $file . ":20: call of findByKey",
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyTheWorkerDirectoryCallsTheKeyOnlyReceiptMethods(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $hits = [];
        foreach (TokenScanner::phpFiles($root) as $path) {
            if (!$this->isAllowed(substr($path, strlen($root) + 1))) {
                $hits = [...$hits, ...$this->scanFile($path)];
            }
        }

        self::assertSame([], $hits, 'findByKey, markReady, markFailed and markReplaced are for the receipt worker only.');
    }

    private function isAllowed(string $relativePath): bool
    {
        foreach (self::ALLOWED_DIRECTORIES as $directory) {
            if (str_starts_with($relativePath, $directory)) {
                return true;
            }
        }

        return false;
    }

    private static function namesGuarded(string $literal): bool
    {
        return preg_match('/(?:\A|::)(?:findbykey|markready|markfailed|markreplaced)\z/i', substr($literal, 1, -1)) === 1;
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
            if (!$token->is(T_STRING) || !in_array(strtolower($token->text), self::METHODS, true)) {
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
