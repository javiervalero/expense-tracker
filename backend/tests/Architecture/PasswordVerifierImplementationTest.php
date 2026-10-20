<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 (A12, A16): `PasswordVerifier` exists so tests can observe the handlers, which must not make it a way to
 * wire a weaker verifier into production. Under src/, only PasswordHasher.php may implement it.
 *
 * It tokenises with PhpToken. Caught: a name whose last segment is PasswordVerifier (plain, qualified or fully
 * qualified, any case) in the list after `implements`, in a named or an anonymous class, and an import that renames
 * it (`use ...\PasswordVerifier as Other`), which would hide a later `implements Other`. Not caught: a class that
 * gets it through an interface extending it, or a name built at run time; review catches those.
 */
final class PasswordVerifierImplementationTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/passwordverifier';
    private const INTERFACE_NAME = 'passwordverifier';

    public function testCleanFixtureImplementsNothing(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':8: use ... as',
            $file . ':10: implements PasswordVerifier',
            $file . ':12: implements \\App\\Auth\\Password\\PasswordVerifier',
            $file . ':14: implements passwordverifier',
            $file . ':20: implements PasswordVerifier',
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyPasswordHasherItselfImplementsPasswordVerifier(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $own = $root . '/Auth/Password/PasswordHasher.php';
        self::assertFileExists($own);
        self::assertNotSame([], $this->scanFile($own), 'The scan must see the one legitimate implementation.');
        $hits = [];
        foreach (TokenScanner::phpFilesExcept($root, $own) as $path) {
            $hits = [...$hits, ...$this->scanFile($path)];
        }

        self::assertSame([], $hits, 'Only PasswordHasher may implement PasswordVerifier in src/.');
    }

    /**
     * @return list<string> "file:line: label" for each implementation or renaming import, in source order
     */
    private function scanFile(string $path): array
    {
        $tokens = TokenScanner::tokens($path);
        $hits = [];

        foreach ($tokens as $index => $token) {
            if (!$token->is(TokenScanner::NAME_TOKENS) || TokenScanner::lastSegment($token->text) !== self::INTERFACE_NAME) {
                continue;
            }
            if (TokenScanner::neighbour($tokens, $index, 1)?->is(T_AS) === true) {
                $hits[] = "{$path}:{$token->line}: use ... as";
            } elseif ($this->isInImplementsList($tokens, $index)) {
                $hits[] = "{$path}:{$token->line}: implements {$token->text}";
            }
        }

        return $hits;
    }

    /**
     * Walks back over names and commas (`implements \Countable, Name`) and says whether `implements` comes first.
     *
     * @param list<\PhpToken> $tokens
     */
    private function isInImplementsList(array $tokens, int $index): bool
    {
        for ($i = $index - 1; isset($tokens[$i]); --$i) {
            $token = $tokens[$i];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, ','])) {
                continue;
            }
            if ($token->is(TokenScanner::NAME_TOKENS)) {
                continue;
            }

            return $token->is(T_IMPLEMENTS);
        }

        return false;
    }
}
