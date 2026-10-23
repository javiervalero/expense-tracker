<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 (B10): a user's partition holds the `PROFILE` item, and ItemStore::query is the one reader that checks for
 * it. Nothing else in src/ may reach DynamoDB's read operations by another road, so the following appear only in
 * ItemStore.php:
 *
 * - the string literals `'Query'`, `'Scan'`, `'ExecuteStatement'`, `'BatchExecuteStatement'`, `'ExecuteTransaction'`,
 *   `'BatchGetItem'` and `'TransactGetItems'` (the operation names ItemStore passes to `send()` and the SDK's
 *   `getCommand()`), exactly and case-sensitively;
 * - calls (`->`, `?->` or `::`) of `scan`, `queryAsync`, `getPaginator`, `getIterator`, `executeStatement`,
 *   `batchExecuteStatement`, `executeTransaction`, `batchGetItem`, `transactGetItems` and the `*Async` form of each, in
 *   any case.
 *
 * It tokenises with PhpToken; comments are ignored. A plain `->query(` call is the business of
 * UserPartitionQueryTest. Not caught: a name built at run time (`'Que' . 'ry'`, `$o->$method()`); review catches those.
 */
final class SdkOperationsTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/sdkoperations';
    private const OPERATIONS = ['Query', 'Scan', 'ExecuteStatement', 'BatchExecuteStatement', 'ExecuteTransaction', 'BatchGetItem', 'TransactGetItems'];
    private const METHODS = [
        'scan', 'scanasync', 'queryasync', 'getpaginator', 'getiterator',
        'executestatement', 'executestatementasync',
        'batchexecutestatement', 'batchexecutestatementasync',
        'executetransaction', 'executetransactionasync',
        'batchgetitem', 'batchgetitemasync',
        'transactgetitems', 'transactgetitemsasync',
    ];

    public function testCleanFixtureHasNoLeak(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ":11: operation literal 'Query'",
            $file . ":12: operation literal 'Scan'",
            $file . ":13: operation literal 'ExecuteStatement'",
            $file . ':14: call of scan',
            $file . ':15: call of scanAsync',
            $file . ':16: call of queryAsync',
            $file . ':17: call of getPaginator',
            $file . ":17: operation literal 'Query'",
            $file . ':18: call of getIterator',
            $file . ":18: operation literal 'Scan'",
            $file . ':19: call of executeStatement',
            $file . ':20: call of EXECUTESTATEMENT',
            $file . ":21: operation literal 'BatchExecuteStatement'",
            $file . ":22: operation literal 'ExecuteTransaction'",
            $file . ":23: operation literal 'BatchGetItem'",
            $file . ":24: operation literal 'TransactGetItems'",
            $file . ':25: call of batchExecuteStatement',
            $file . ':26: call of executeTransaction',
            $file . ':27: call of batchGetItem',
            $file . ':28: call of transactGetItems',
            $file . ':29: call of batchExecuteStatementAsync',
            $file . ':30: call of executeTransactionAsync',
            $file . ':31: call of batchGetItemAsync',
            $file . ':32: call of TransactGetItemsAsync',
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyItemStoreUsesTheSdkReadOperations(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $own = $root . '/Infrastructure/DynamoDb/ItemStore.php';
        self::assertFileExists($own);
        $hits = [];
        foreach (TokenScanner::phpFilesExcept($root, $own) as $path) {
            $hits = [...$hits, ...$this->scanFile($path)];
        }

        self::assertSame([], $hits, 'Read through ItemStore::query(), the one reader that refuses a PROFILE item.');
    }

    /**
     * @return list<string> "file:line: label" for each use, in source order
     */
    private function scanFile(string $path): array
    {
        $tokens = TokenScanner::tokens($path);
        $hits = [];
        foreach ($tokens as $index => $token) {
            $label = $this->label($tokens, $index);
            if ($label !== null) {
                $hits[] = "{$path}:{$token->line}: {$label}";
            }
        }

        return $hits;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function label(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $inner = substr($token->text, 1, -1);

            return in_array($inner, self::OPERATIONS, true) ? "operation literal '{$inner}'" : null;
        }
        if (!$token->is(T_STRING) || !in_array(strtolower($token->text), self::METHODS, true)) {
            return null;
        }
        $isCall = TokenScanner::neighbour($tokens, $index, -1)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]) === true
            && TokenScanner::neighbour($tokens, $index, 1)?->text === '(';

        return $isCall ? "call of {$token->text}" : null;
    }
}
