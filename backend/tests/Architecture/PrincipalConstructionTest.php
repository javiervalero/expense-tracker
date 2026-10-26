<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 (B10): the key builders of a user's items take a `Principal`, so the partition can only come from a session
 * that was checked. For that to mean anything, a Principal is made in one place: nothing under src/ may construct
 * `Principal` with `new`, except the session authenticator.
 *
 * It tokenises with PhpToken. Caught: `new` followed by a name whose last segment is Principal (plain, qualified or
 * fully qualified, any case), and an import that renames it (`use ...\Principal as Other`). In a file that mentions
 * Principal, it also flags dynamic instantiation (`new $class`, `new (...)`) and `Principal::class` in a file that
 * also uses Reflection* or newInstance*. A clone, a clone-with and unserialisation are refused by Principal itself
 * (PrincipalTest). Not caught: a class name built as a string, or passed in from another file, then instantiated
 * elsewhere: tokens cannot follow that, so review catches it. A `new` after `::` or `function` is a method name
 * (`Category::new(`), not an instantiation. In the file that declares Principal, `new self`, `new static` and
 * `new parent` are flagged too.
 */
final class PrincipalConstructionTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/principal';
    private const CLASS_NAME = 'principal';

    public function testCleanFixtureHasNoConstruction(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':8: use ... as',
            $file . ':15: new Principal',
            $file . ':16: new \\App\\Auth\\Session\\Principal',
            $file . ':17: new principal',
        ], $this->scanFile($file));
    }

    public function testDynamicInstantiationAndReflectionInAFileThatMentionsPrincipalAreFlagged(): void
    {
        $file = self::FIXTURES . '/leaky/Dynamic.php';

        self::assertSame([
            $file . ':13: Principal::class with Reflection or newInstance',
            $file . ':16: dynamic new',
            $file . ':17: dynamic new',
            $file . ':21: Principal::class with Reflection or newInstance',
        ], $this->scanFile($file));
    }

    public function testAMethodNamedNewIsNotAnInstantiation(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/MethodNamedNew.php'));
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/AmpersandMethodNamedNew.php'));
    }

    public function testADynamicNewNextToAStaticMethodCalledNewIsStillFlagged(): void
    {
        $file = self::FIXTURES . '/leaky/DynamicNextToStaticNew.php';

        self::assertSame([
            $file . ':15: dynamic new',
            $file . ':16: dynamic new',
            $file . ':17: dynamic new',
        ], $this->scanFile($file));
    }

    public function testTheFileThatDeclaresPrincipalMayNotCopyItWithNewSelfStaticOrParent(): void
    {
        $file = self::FIXTURES . '/leaky/DeclaresPrincipal.php';

        self::assertSame([
            $file . ':13: new self in the declaring file',
            $file . ':13: new static in the declaring file',
            $file . ':13: new parent in the declaring file',
        ], $this->scanFile($file));
    }

    public function testNamingPrincipalOrInstantiatingDynamicallyElsewhereIsNotFlagged(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/NamesThePrincipalClass.php'));
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Unrelated.php'));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyTheSessionAuthenticatorConstructsAPrincipal(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $own = $root . '/Auth/Session/SessionAuthenticator.php';
        self::assertFileExists($own);
        $hits = [];
        foreach (TokenScanner::phpFilesExcept($root, $own) as $path) {
            $hits = [...$hits, ...$this->scanFile($path)];
        }

        self::assertSame([], $hits, 'A Principal comes from SessionAuthenticator (Principal::of($request)), never from new Principal.');
    }

    /**
     * @return list<string> "file:line: label" for each construction, in source order
     */
    private function scanFile(string $path): array
    {
        $tokens = TokenScanner::tokens($path);
        $mentions = $this->mentionsPrincipal($tokens);
        $usesReflection = $this->usesReflection($tokens);
        $declares = $this->declaresPrincipal($tokens);
        $hits = [];

        foreach ($tokens as $index => $token) {
            $label = $this->nameLabel($tokens, $index)
                ?? ($mentions ? $this->dynamicLabel($tokens, $index, $usesReflection) : null)
                ?? ($declares ? $this->selfCopyLabel($tokens, $index) : null);
            if ($label !== null) {
                $hits[] = "{$path}:{$token->line}: {$label}";
            }
        }

        return $hits;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function declaresPrincipal(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            $next = TokenScanner::neighbour($tokens, $index, 1);
            if ($token->is(T_CLASS) && $next?->is(T_STRING) === true && strtolower($next->text) === self::CLASS_NAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * In the file that declares Principal, `new self`, `new static` and `new parent` would make one without
     * SessionAuthenticator.
     *
     * @param list<\PhpToken> $tokens
     */
    private function selfCopyLabel(array $tokens, int $index): ?string
    {
        if (!$tokens[$index]->is(T_NEW)) {
            return null;
        }
        $next = TokenScanner::neighbour($tokens, $index, 1);
        $name = strtolower((string) $next?->text);

        return in_array($name, ['self', 'static', 'parent'], true) ? "new {$name} in the declaring file" : null;
    }

    /**
     * A `new` that follows `::` or `function` (with an optional `&`) is a method name, as in `Category::new(` or
     * `public static function new(`. (`->new` and `?->new` are lexed as plain names, never as T_NEW.)
     *
     * @param list<\PhpToken> $tokens
     */
    private function isMethodName(array $tokens, int $index): bool
    {
        for ($i = $index - 1; isset($tokens[$i]); --$i) {
            if ($tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]) || $tokens[$i]->text === '&') {
                continue;
            }

            return $tokens[$i]->is([T_DOUBLE_COLON, T_FUNCTION]);
        }

        return false;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function mentionsPrincipal(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token->is(TokenScanner::NAME_TOKENS) && TokenScanner::lastSegment($token->text) === self::CLASS_NAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function usesReflection(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token->is(TokenScanner::NAME_TOKENS) && (str_starts_with(TokenScanner::lastSegment($token->text), 'reflection') || str_starts_with(strtolower($token->text), 'newinstance'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function nameLabel(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];
        if (!$token->is(TokenScanner::NAME_TOKENS) || TokenScanner::lastSegment($token->text) !== self::CLASS_NAME) {
            return null;
        }
        if (TokenScanner::neighbour($tokens, $index, -1)?->is(T_NEW) === true) {
            return "new {$token->text}";
        }

        return TokenScanner::neighbour($tokens, $index, 1)?->is(T_AS) === true ? 'use ... as' : null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function dynamicLabel(array $tokens, int $index, bool $usesReflection): ?string
    {
        $token = $tokens[$index];
        if ($token->is(T_NEW)) {
            if ($this->isMethodName($tokens, $index)) {
                return null;
            }
            $next = TokenScanner::neighbour($tokens, $index, 1);

            return $next?->is(T_VARIABLE) === true || $next?->text === '(' ? 'dynamic new' : null;
        }
        $isClassConstant = $token->is(TokenScanner::NAME_TOKENS) && TokenScanner::lastSegment($token->text) === self::CLASS_NAME
            && TokenScanner::neighbour($tokens, $index, 1)?->is(T_DOUBLE_COLON) === true
            && strtolower((string) TokenScanner::neighbour($tokens, $index + 1, 1)?->text) === 'class';

        return $isClassConstant && $usesReflection ? 'Principal::class with Reflection or newInstance' : null;
    }
}
