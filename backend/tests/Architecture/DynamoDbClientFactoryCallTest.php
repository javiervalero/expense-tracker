<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 (B10): the DynamoDB client is built once, in the composition root (`ApiFactory`), and handed to ItemStore.
 * Nothing else under src/ may call `DynamoDbClientFactory::create(`, so no second, unguarded client exists.
 *
 * It tokenises with PhpToken. Caught: `DynamoDbClientFactory::create(` (plain, qualified or fully qualified name, any
 * case), and an import that renames the factory (`use ...\DynamoDbClientFactory as Other`), which would hide a later
 * call. Not caught: a class name held in a string or a variable; review catches those.
 */
final class DynamoDbClientFactoryCallTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/clientfactory';
    private const CLASS_NAME = 'dynamodbclientfactory';

    public function testCleanFixtureHasNoCall(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':8: use ... as',
            $file . ':15: DynamoDbClientFactory::create(',
            $file . ':16: \\App\\Infrastructure\\DynamoDb\\DynamoDbClientFactory::create(',
            $file . ':17: dynamodbclientfactory::create(',
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyTheCompositionRootCreatesTheDynamoDbClient(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $own = $root . '/Api/ApiFactory.php';
        self::assertFileExists($own);
        $hits = [];
        foreach (TokenScanner::phpFilesExcept($root, $own) as $path) {
            $hits = [...$hits, ...$this->scanFile($path)];
        }

        self::assertSame([], $hits, 'Build the DynamoDB client only in ApiFactory and pass the ItemStore on.');
    }

    /**
     * @return list<string> "file:line: label" for each use, in source order
     */
    private function scanFile(string $path): array
    {
        $tokens = TokenScanner::tokens($path);
        $hits = [];
        foreach ($tokens as $index => $token) {
            if (!$token->is(TokenScanner::NAME_TOKENS) || TokenScanner::lastSegment($token->text) !== self::CLASS_NAME) {
                continue;
            }
            $next = TokenScanner::neighbour($tokens, $index, 1);
            if ($next?->is(T_AS) === true) {
                $hits[] = "{$path}:{$token->line}: use ... as";
            } elseif ($next?->is(T_DOUBLE_COLON) === true && $this->isCreateCall($tokens, $index)) {
                $hits[] = "{$path}:{$token->line}: {$token->text}::create(";
            }
        }

        return $hits;
    }

    /**
     * @param list<\PhpToken> $tokens index of the class name, followed by `::`
     */
    private function isCreateCall(array $tokens, int $nameIndex): bool
    {
        $significant = array_values(array_filter(
            array_slice($tokens, $nameIndex + 1, 8),
            static fn(\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        return ($significant[1] ?? null)?->is(T_STRING) === true
            && strtolower($significant[1]->text) === 'create'
            && ($significant[2] ?? null)?->text === '(';
    }
}
