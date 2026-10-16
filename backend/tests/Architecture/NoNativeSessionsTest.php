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
 * It tokenises with PhpToken. Caught: a forbidden name as any name token (`T_STRING` or a qualified, fully
 * qualified or relative name) wherever it appears, so also `use function setcookie as x`, `setcookie(...)`, a
 * constant (`INPUT_COOKIE`) or a class (`Mt19937`); every `session_*` function; a string that is exactly a
 * forbidden function name (a string callable) or starts with `set-cookie:` (a `header()` call); and the variables
 * `$_COOKIE`, `$_SESSION`, `$_REQUEST` and `$GLOBALS['_COOKIE']`.
 *
 * Not caught: names built at run time (`$name()`, `'set' . 'cookie'`, interpolated strings), `call_user_func`
 * with a computed name, `\Foo\rand()` in a non-global namespace (a different function), and anything reached
 * through `$GLOBALS` with a computed key. Comments, prose strings and look-alikes (`$this->rand()`,
 * `Foo::setcookie()`, a method called `shuffle`) are not flagged. Review catches what a static scan cannot.
 */
final class NoNativeSessionsTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/nativesessions';
    private const FORBIDDEN_FUNCTIONS = [
        'setcookie', 'setrawcookie', 'rand', 'mt_rand', 'uniqid', 'lcg_value',
        'array_rand', 'shuffle', 'str_shuffle', 'srand', 'mt_srand', 'getallheaders',
    ];
    private const FORBIDDEN_FUNCTION_PREFIX = 'session_';
    private const FORBIDDEN_CONSTANTS = ['input_cookie'];
    private const FORBIDDEN_CLASSES = ['mt19937'];
    private const FORBIDDEN_VARIABLES = ['$_COOKIE', '$_SESSION', '$_REQUEST'];
    private const NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
    private const SET_COOKIE_HEADER_PREFIX = 'set-cookie:';

    public function testCleanFixtureHasNoForbiddenUse(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':7: setcookie',
            $file . ':13: session_start',
            $file . ':14: setcookie',
            $file . ':15: \\setrawcookie',
            $file . ':16: $_COOKIE',
            $file . ':17: rand',
            $file . ':17: MT_RAND',
            $file . ':17: mt_rand',
            $file . ':18: uniqid',
            $file . ':19: \\lcg_value',
            $file . ':24: session_regenerate_id',
            $file . ':26: array_rand',
            $file . ':27: shuffle',
            $file . ':28: str_shuffle',
            $file . ':29: srand',
            $file . ':30: mt_srand',
            $file . ':31: getallheaders',
            $file . ':32: INPUT_COOKIE',
            $file . ':33: \\Random\\Engine\\Mt19937',
            $file . ':34: namespace\\rand',
            $file . ':35: $_SESSION',
            $file . ':35: $_REQUEST',
            $file . ":35: \$GLOBALS['_COOKIE']",
            $file . ":36: 'setcookie'",
            $file . ":37: '\\Session_Id'",
            $file . ":38: 'Set-Cookie: a=b'",
            $file . ':39: setcookie',
        ], $this->scanFile($file));
    }

    public function testEachGuardedDirectoryExists(): void
    {
        foreach (['src', 'public'] as $directory) {
            self::assertDirectoryExists(dirname(__DIR__, 2) . '/' . $directory, "The guard scans {$directory}/ but it does not exist; fix the guard, do not let it pass on nothing.");
        }
    }

    #[DataProvider('guardedDirectories')]
    public function testApplicationCodeUsesNoNativeSessionsCookiesOrWeakRandom(string $directory): void
    {
        $root = dirname(__DIR__, 2) . '/' . $directory;
        self::assertDirectoryExists($root, "The guard scans {$directory}/ but it does not exist.");
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root,
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
            $label = $this->forbiddenLabel($tokens, $index);
            if ($label !== null) {
                $hits[] = "{$path}:{$token->line}: {$label}";
            }
        }

        return $hits;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function forbiddenLabel(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];

        return match (true) {
            $token->is(T_VARIABLE) => $this->forbiddenVariable($tokens, $index),
            $token->is(self::NAME_TOKENS) => $this->isForbiddenName($tokens, $index) ? $token->text : null,
            $token->is(T_CONSTANT_ENCAPSED_STRING) => $this->isForbiddenString($token->text) ? $token->text : null,
            default => null,
        };
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function forbiddenVariable(array $tokens, int $index): ?string
    {
        $name = $tokens[$index]->text;
        if (in_array($name, self::FORBIDDEN_VARIABLES, true)) {
            return $name;
        }
        if ($name !== '$GLOBALS' || $this->neighbour($tokens, $index, 1)?->text !== '[') {
            return null;
        }
        $key = $this->neighbour($tokens, $index + 1, 1);

        return $key?->is(T_CONSTANT_ENCAPSED_STRING) === true && $this->unquoted($key->text) === '_COOKIE'
            ? "\$GLOBALS['_COOKIE']"
            : null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function isForbiddenName(array $tokens, int $index): bool
    {
        $previousIndex = $this->neighbourIndex($tokens, $index, -1);
        $previous = $previousIndex === null ? null : $tokens[$previousIndex];
        if ($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CONST]) === true) {
            return false;
        }
        // A definition (`function rand()`), but not an import (`use function setcookie`).
        if ($previous?->is(T_FUNCTION) === true) {
            $beforeFunction = $this->neighbour($tokens, $previousIndex, -1);
            if ($beforeFunction?->is(T_USE) !== true) {
                return false;
            }
        }
        $name = strtolower(ltrim($tokens[$index]->text, '\\'));
        $name = str_starts_with($name, 'namespace\\') ? substr($name, strlen('namespace\\')) : $name;
        $lastSegment = substr($name, (int) strrpos('\\' . $name, '\\'));

        return $this->isForbiddenFunctionName($name)
            || in_array($name, self::FORBIDDEN_CONSTANTS, true)
            || in_array($lastSegment, self::FORBIDDEN_CLASSES, true);
    }

    private function isForbiddenFunctionName(string $name): bool
    {
        return in_array($name, self::FORBIDDEN_FUNCTIONS, true)
            || (str_starts_with($name, self::FORBIDDEN_FUNCTION_PREFIX) && preg_match('/\A[a-z0-9_]+\z/', $name) === 1);
    }

    private function isForbiddenString(string $literal): bool
    {
        $content = strtolower($this->unquoted($literal));

        return $this->isForbiddenFunctionName(ltrim($content, '\\')) || str_starts_with($content, self::SET_COOKIE_HEADER_PREFIX);
    }

    private function unquoted(string $literal): string
    {
        return substr($literal, 1, -1);
    }

    /**
     * The nearest token in a direction that is not whitespace or a comment.
     *
     * @param list<\PhpToken> $tokens
     */
    private function neighbour(array $tokens, int $index, int $step): ?\PhpToken
    {
        $found = $this->neighbourIndex($tokens, $index, $step);

        return $found === null ? null : $tokens[$found];
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function neighbourIndex(array $tokens, int $index, int $step): ?int
    {
        for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
            if (!$tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
    }
}
