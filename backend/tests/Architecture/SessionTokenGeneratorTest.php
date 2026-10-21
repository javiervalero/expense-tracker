<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Phase 2 (security L1): the optional token generator of `SessionRepository` exists for tests. Nothing under src/ may
 * construct a `SessionRepository` with a third argument or with the named argument `generateToken`, so production can
 * only ever get unpredictable tokens from `SessionToken::generate()`.
 *
 * It tokenises with PhpToken. Caught: `new` followed by a name whose last segment is SessionRepository (plain,
 * qualified or fully qualified, any case) with more than two top-level arguments or a `generateToken:` argument, and
 * an import that renames it (`use ...\SessionRepository as Other`). Not caught: argument unpacking (`...$args`), `new
 * $class`, and reflection; review catches those.
 */
final class SessionTokenGeneratorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/sessiontokengenerator';
    private const CLASS_NAME = 'sessionrepository';
    private const NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
    private const MAX_PRODUCTION_ARGUMENTS = 2;

    public function testCleanFixtureHasNoInjectedGenerator(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':8: use ... as',
            $file . ':15: new SessionRepository with a third argument',
            $file . ':16: new \\App\\Auth\\Session\\SessionRepository with a third argument',
            $file . ':17: new sessionrepository with a third argument',
            $file . ':18: new SessionRepository with a third argument',
            $file . ':19: new SessionRepository with a third argument',
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testNothingUnderSrcInjectsATokenGenerator(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $hits = [...$hits, ...$this->scanFile($entry->getPathname())];
            }
        }

        self::assertSame([], $hits, 'Construct SessionRepository with ItemStore and Clock only; the generator is for tests.');
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
            if (!$token->is(self::NAME_TOKENS) || !$this->isRepositoryName($token->text)) {
                continue;
            }
            $previous = $this->neighbour($tokens, $index, -1);
            $next = $this->neighbour($tokens, $index, 1);
            if ($previous?->is(T_NEW) === true && $this->hasInjectedGenerator($tokens, $index)) {
                $hits[] = "{$path}:{$token->line}: new {$token->text} with a third argument";
            } elseif ($next?->is(T_AS) === true) {
                $hits[] = "{$path}:{$token->line}: use ... as";
            }
        }

        return $hits;
    }

    /**
     * @param list<\PhpToken> $tokens
     * @param int $nameIndex the class name after `new`
     */
    private function hasInjectedGenerator(array $tokens, int $nameIndex): bool
    {
        $open = $this->nextSignificantIndex($tokens, $nameIndex);
        if ($open === null || $tokens[$open]->text !== '(') {
            return false;
        }
        $depth = 0;
        $arguments = 0;
        $argumentStarted = false;
        for ($i = $open; isset($tokens[$i]); ++$i) {
            $text = $tokens[$i]->text;
            if ($tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($text === '(' || $text === '[' || $text === '{') {
                ++$depth;
                $argumentStarted = $argumentStarted || $depth > 1;
                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                --$depth;
                if ($depth === 0) {
                    return ($argumentStarted ? $arguments + 1 : $arguments) > self::MAX_PRODUCTION_ARGUMENTS;
                }
                continue;
            }
            if ($depth === 1 && $text === ',') {
                ++$arguments;
                $argumentStarted = false;
                continue;
            }
            if ($depth === 1 && $tokens[$i]->is(T_STRING) && strtolower($text) === 'generatetoken' && $this->nextText($tokens, $i) === ':') {
                return true;
            }
            $argumentStarted = true;
        }

        return false;
    }

    private function isRepositoryName(string $name): bool
    {
        $name = strtolower($name);

        return substr($name, (int) strrpos('\\' . $name, '\\')) === self::CLASS_NAME;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function nextText(array $tokens, int $index): ?string
    {
        $next = $this->nextSignificantIndex($tokens, $index);

        return $next === null ? null : $tokens[$next]->text;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function nextSignificantIndex(array $tokens, int $index): ?int
    {
        for ($i = $index + 1; isset($tokens[$i]); ++$i) {
            if (!$tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
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
