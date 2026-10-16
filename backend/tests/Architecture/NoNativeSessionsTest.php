<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 (A17, A21): sessions and cookies are our own (a DynamoDB item and a `Response` header), and every
 * random value comes from `random_bytes`. PHP's native session, cookie and weak random functions are forbidden
 * in src/ and public/.
 *
 * It tokenises with PhpToken, so comments, strings and look-alikes (`$this->rand()`, `Foo::setcookie()`) do not
 * cause false positives (a fully qualified `\\rand()` is caught). Names built at run time are invisible to a static scan; review catches those.
 */
final class NoNativeSessionsTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/nativesessions';
    private const FORBIDDEN_FUNCTIONS = ['session_start', 'setcookie', 'setrawcookie', 'rand', 'mt_rand', 'uniqid', 'lcg_value'];

    public function testCleanFixtureHasNoForbiddenUse(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':11: session_start',
            $file . ':12: setcookie',
            $file . ':13: \\setrawcookie',
            $file . ':14: $_COOKIE',
            $file . ':15: rand',
            $file . ':15: MT_RAND',
            $file . ':15: mt_rand',
            $file . ':16: uniqid',
            $file . ':17: \\lcg_value',
        ], $this->scanFile($file));
    }

    #[DataProvider('guardedDirectories')]
    public function testApplicationCodeUsesNoNativeSessionsCookiesOrWeakRandom(string $directory): void
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            dirname(__DIR__, 2) . '/' . $directory,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($files as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $hits = [...$hits, ...$this->scanFile($entry->getPathname())];
            }
        }

        self::assertSame([], $hits, "Native sessions, cookies or weak random used in {$directory}/");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guardedDirectories(): iterable
    {
        yield 'src' => ['src'];
        yield 'public' => ['public'];
    }

    /**
     * @return list<string> "file:line: name" for each forbidden use, in source order
     */
    private function scanFile(string $path): array
    {
        $source = file_get_contents($path);
        self::assertIsString($source, $path);
        $tokens = array_values(\PhpToken::tokenize($source));
        $hits = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_VARIABLE) && $token->text === '$_COOKIE') {
                $hits[] = "{$path}:{$token->line}: \$_COOKIE";
            } elseif ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $this->isForbiddenCall($tokens, $index)) {
                $hits[] = "{$path}:{$token->line}: {$token->text}";
            }
        }

        return $hits;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function isForbiddenCall(array $tokens, int $index): bool
    {
        if (!in_array(strtolower(ltrim($tokens[$index]->text, '\\')), self::FORBIDDEN_FUNCTIONS, true)) {
            return false;
        }
        $next = $this->neighbour($tokens, $index, 1);
        $previous = $this->neighbour($tokens, $index, -1);

        return $next?->text === '(' && !($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]) ?? false);
    }

    /**
     * The nearest token in a direction that is not whitespace or a comment.
     *
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
