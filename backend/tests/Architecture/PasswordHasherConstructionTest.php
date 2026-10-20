<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 (A12, A16): production code gets its hasher from `PasswordHasher::production()`, so it can never run with
 * the cheap parameters the unit tests use. Nothing under src/ may construct `PasswordHasher` with `new`, except
 * PasswordHasher.php itself.
 *
 * It tokenises with PhpToken. Caught: `new` followed by a name whose last segment is PasswordHasher (plain, qualified
 * or fully qualified, any case), and an import that renames it (`use ...\PasswordHasher as Other`), which would hide
 * a later `new Other`. Not caught: `new $class`, `new` of a name built at run time, and reflection
 * (`newInstance`); review catches those.
 */
final class PasswordHasherConstructionTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/passwordhasher';
    private const CLASS_NAME = 'passwordhasher';

    public function testCleanFixtureHasNoConstruction(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':8: use ... as',
            $file . ':15: new PasswordHasher',
            $file . ':16: new \\App\\Auth\\Password\\PasswordHasher',
            $file . ':17: new passwordhasher',
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyPasswordHasherItselfConstructsAPasswordHasher(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $own = $root . '/Auth/Password/PasswordHasher.php';
        self::assertFileExists($own);
        $hits = [];
        foreach (TokenScanner::phpFilesExcept($root, $own) as $path) {
            $hits = [...$hits, ...$this->scanFile($path)];
        }

        self::assertSame([], $hits, 'Use PasswordHasher::production() in src/, never new PasswordHasher.');
    }

    /**
     * @return list<string> "file:line: label" for each construction, in source order
     */
    private function scanFile(string $path): array
    {
        $tokens = TokenScanner::tokens($path);
        $hits = [];

        foreach ($tokens as $index => $token) {
            if (!$token->is(TokenScanner::NAME_TOKENS) || !$this->isHasherName($token->text)) {
                continue;
            }
            $previous = TokenScanner::neighbour($tokens, $index, -1);
            $next = TokenScanner::neighbour($tokens, $index, 1);
            if ($previous?->is(T_NEW) === true) {
                $hits[] = "{$path}:{$token->line}: new {$token->text}";
            } elseif ($next?->is(T_AS) === true) {
                $hits[] = "{$path}:{$token->line}: use ... as";
            }
        }

        return $hits;
    }

    private function isHasherName(string $name): bool
    {
        return TokenScanner::lastSegment($name) === self::CLASS_NAME;
    }
}
