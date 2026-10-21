<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Phase 2 (A12 follow-up): DynamoDbClientFactory is the one place that builds an AWS client, and credentials come
 * only from the SDK's default chain (the execution role on Lambda, the environment locally). Nothing else under src/
 * may build `DynamoDbClient` or `Sdk` with `new`, or pass a `'credentials'` option (static keys in code).
 *
 * It tokenises with PhpToken. Caught: `new` followed by a name whose last segment is DynamoDbClient or Sdk (plain,
 * qualified or fully qualified, any case), an import that renames either (`use Aws\Sdk as Other`), and a string
 * literal that is exactly `credentials`. Not caught: names built at run time, other clients, and a credentials
 * option under a computed key; review catches those.
 */
final class AwsClientConstructionTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/awsclient';
    private const CLIENT_NAMES = ['dynamodbclient', 'sdk'];
    private const NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    public function testCleanFixtureBuildsNoClient(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':8: use ... as',
            $file . ':15: new DynamoDbClient',
            $file . ':15: credentials option',
            $file . ':16: new \\Aws\\Sdk',
            $file . ':17: new sdk',
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyTheClientFactoryBuildsAClientOrPassesCredentials(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $own = $root . '/Infrastructure/DynamoDb/DynamoDbClientFactory.php';
        self::assertFileExists($own);
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php' && $entry->getPathname() !== $own) {
                $hits = [...$hits, ...$this->scanFile($entry->getPathname())];
            }
        }

        self::assertSame([], $hits, 'Build clients only in DynamoDbClientFactory and never pass credentials: the SDK finds them.');
    }

    public function testTheClientFactoryItselfPassesNoCredentials(): void
    {
        $own = dirname(__DIR__, 2) . '/src/Infrastructure/DynamoDb/DynamoDbClientFactory.php';

        $labels = array_map(static fn(string $hit): string => substr($hit, (int) strrpos($hit, ': ') + 2), $this->scanFile($own));

        self::assertNotContains('credentials option', $labels);
    }

    /**
     * @return list<string> "file:line: label" for each finding, in source order
     */
    private function scanFile(string $path): array
    {
        $source = file_get_contents($path);
        self::assertIsString($source, $path);
        $tokens = array_values(\PhpToken::tokenize($source));
        $hits = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING) && trim($token->text, '\'"') === 'credentials') {
                $hits[] = "{$path}:{$token->line}: credentials option";
            }
            if (!$token->is(self::NAME_TOKENS) || !$this->isClientName($token->text)) {
                continue;
            }
            if ($this->neighbour($tokens, $index, -1)?->is(T_NEW) === true) {
                $hits[] = "{$path}:{$token->line}: new {$token->text}";
            } elseif ($this->neighbour($tokens, $index, 1)?->is(T_AS) === true) {
                $hits[] = "{$path}:{$token->line}: use ... as";
            }
        }

        return $hits;
    }

    private function isClientName(string $name): bool
    {
        $name = strtolower($name);

        return in_array(substr($name, (int) strrpos('\\' . $name, '\\')), self::CLIENT_NAMES, true);
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function neighbour(array $tokens, int $index, int $step): ?\PhpToken
    {
        for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
            if (!$tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
