<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 (A13): a login throttle bucket is refunded or reset only through `LoginThrottle::succeeded()`, which needs a
 * one-shot LoginAttempt. Nothing under src/ except LoginThrottle.php may call `issuedBy`, `->consume(`, a throttle
 * store's `->refund(` / `->delete(`, or name one of those methods in a string (a callable). Method declarations
 * (LoginAttempt.php, ThrottleStore.php, ThrottleCounter.php) are not calls and never match, so those files need no
 * exclusion.
 *
 * It tokenises with PhpToken. `issuedBy` is flagged after `::` or `->` / `?->` whatever the owner is, so aliases,
 * `self::`, `$class::` and instance calls are caught and no `use ... as` check is needed. `consume` and `refund` are
 * flagged as calls (any case). `->delete(` is also the name of unrelated methods (SessionRepository), so it is flagged
 * only in a file that mentions a name whose last segment contains "throttle" (ThrottleStore, ThrottleCounter,
 * decorators such as CachedThrottleStore), which is how any code gets hold of a throttle store. String literals equal
 * to `consume`, `refund` or `issuedBy` are flagged too.
 *
 * Accepted limits, left to review: dynamic method names (`$o->$method()`), reflection or `Closure::bind` resetting
 * `used`, and a store reached without naming a throttle type (an untyped value passed in). If code outside
 * LoginThrottle ever legitimately needs a method called `refund` or `consume`, rename it, or add an explicit,
 * commented exclusion here; do not weaken the rule.
 */
final class ThrottleRefundBypassTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/throttlebypass';
    private const GUARDED_NAMES = ['consume', 'refund', 'issuedby'];

    public function testCleanFixturesHaveNoBypass(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Unrelated.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':16: ::issuedBy',
            $file . ':17: ::issuedBy',
            $file . ':18: ::issuedBy',
            $file . ':19: ::issuedBy',
            $file . ':20: ->consume(',
            $file . ':21: ->consume(',
            $file . ':22: ->refund(',
            $file . ':24: ->consume(',
            $file . ':27: ->refund(',
            $file . ':28: ->delete(',
            $file . ":29: string 'refund'",
            $file . ':34: ->delete(',
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testOnlyLoginThrottleRefundsOrConsumesAnAttempt(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $own = $root . '/Auth/Throttle/LoginThrottle.php';
        self::assertFileExists($own);
        $hits = [];
        foreach (TokenScanner::phpFilesExcept($root, $own) as $path) {
            $hits = [...$hits, ...$this->scanFile($path)];
        }

        self::assertSame([], $hits, 'Refund or reset a throttle only through LoginThrottle::succeeded().');
    }

    /**
     * @return list<string> "file:line: label" for each bypass, in source order
     */
    private function scanFile(string $path): array
    {
        $tokens = TokenScanner::tokens($path);
        $mentionsThrottle = false;
        foreach ($tokens as $token) {
            if ($token->is(TokenScanner::NAME_TOKENS) && str_contains(TokenScanner::lastSegment($token->text), 'throttle')) {
                $mentionsThrottle = true;
                break;
            }
        }
        $hits = [];

        foreach ($tokens as $index => $token) {
            $label = $this->label($tokens, $index, $mentionsThrottle);
            if ($label !== null) {
                $hits[] = "{$path}:{$token->line}: {$label}";
            }
        }

        return $hits;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function label(array $tokens, int $index, bool $mentionsThrottle): ?string
    {
        $token = $tokens[$index];
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $value = strtolower(trim($token->text, '\'"'));

            return in_array($value, self::GUARDED_NAMES, true) ? "string '" . trim($token->text, '\'"') . "'" : null;
        }
        if (!$token->is(T_STRING)) {
            return null;
        }
        $name = strtolower($token->text);
        $previous = TokenScanner::neighbour($tokens, $index, -1);
        $isObjectAccess = $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) === true;
        if ($name === 'issuedby' && ($isObjectAccess || $previous?->is(T_DOUBLE_COLON) === true)) {
            return ($isObjectAccess ? '->' : '::') . 'issuedBy';
        }
        $isCall = $isObjectAccess && TokenScanner::neighbour($tokens, $index, 1)?->text === '(';
        if ($isCall && ($name === 'consume' || $name === 'refund' || ($name === 'delete' && $mentionsThrottle))) {
            return '->' . $name . '(';
        }

        return null;
    }
}
