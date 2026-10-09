<?php

declare(strict_types=1);

namespace App\Tests\Support;

use InvalidArgumentException;

/**
 * Finds references to the Bref runtime and to Lambda-only environment variables in PHP source.
 *
 * Why: ADR 0012 keeps application code (src/, public/) runtime-agnostic. It must run unchanged under
 * php-fpm, in the local worker and on Lambda, so only the Lambda entrypoints may know about Bref.
 *
 * It tokenises with token_get_all() instead of grepping, so comments and look-alike words do not cause
 * false positives. What is flagged:
 *
 * - RULE_CLASS: a name in the global `Bref\` namespace (`use Bref\X`, `new \Bref\X`, `Bref\X::y()`,
 *   `use Bref\{A, B}`, `namespace Bref\X`), the same inside a string literal ('Bref\\Event\\Foo') and
 *   inside a docblock (`@param \Bref\Context $c`, because static analysis reads docblock types).
 *   Matching is case-insensitive, like PHP class names. `App\Bref\X` is our own namespace: not flagged.
 * - RULE_ENV: inside any string literal (so `getenv()`, `$_SERVER[]`, `$_ENV[]`, `putenv()`,
 *   `array_key_exists()`, interpolated strings and heredocs are all covered), a variable name that only
 *   exists on Lambda or in the Bref runtime: `AWS_LAMBDA_*`, `LAMBDA_TASK_ROOT`, `LAMBDA_RUNTIME_DIR`
 *   and `BREF_*`. Matching is case-sensitive and respects word boundaries (`MY_AWS_LAMBDA_X` is fine).
 *
 * Comments: namespaced `\Bref\...` names inside doc comments (docblocks) ARE flagged, on purpose,
 * because PHPStan reads docblock types. Only plain `//` and `#` comments, and the prose of
 * docblocks (no namespaced name), are ignored, so the boundary may be explained in words.
 *
 * Not flagged: inline HTML and the bare word `Bref` without a namespace separator.
 *
 * Known gaps (a static token scan cannot close them; review catches the rest):
 * - `AWS_EXECUTION_ENV` and `_HANDLER` are Lambda-only variables but are not matched (the patterns cover
 *   `AWS_LAMBDA_*`, `LAMBDA_TASK_ROOT`, `LAMBDA_RUNTIME_DIR` and `BREF_*`); kept as is on purpose.
 * - Names built at run time ('AWS_' . 'LAMBDA_X', variable class names) are invisible.
 * - `use Bref;` (a bare, namespace-less name) is allowed.
 */
final class ForbiddenReferenceScanner
{
    public const RULE_CLASS = 'Bref class reference';
    public const RULE_ENV = 'Lambda environment variable';

    /** A namespaced Bref reference inside text; the lookbehind rejects `App\Bref\X` and `XBref\Y`. */
    private const CLASS_IN_TEXT = '/(?<![A-Za-z0-9_\\\\])\\\\{0,2}Bref\\\\{1,2}(?=[A-Za-z_])/i';

    /** A Lambda or Bref variable name inside a string literal. */
    private const ENV_IN_STRING = '/(?<![A-Za-z0-9_])(?:AWS_LAMBDA_[A-Za-z0-9_]*|(?:LAMBDA_TASK_ROOT|LAMBDA_RUNTIME_DIR)(?![A-Za-z0-9_])|BREF_[A-Za-z0-9_]+)/';

    /**
     * @return list<ForbiddenReference>
     */
    public function scanSource(string $source, string $file = '<source>'): array
    {
        $tokens = token_get_all($source);
        $hits = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;

            foreach ($this->matchesIn($id, $text, $tokens[$index + 1] ?? null) as [$rule, $match, $offset]) {
                $hits[] = new ForbiddenReference($file, $line + substr_count($text, "\n", 0, $offset), $rule, $match);
            }
        }

        return $hits;
    }

    /**
     * @return list<ForbiddenReference>
     */
    public function scanFile(string $path): array
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        if ($source === false) {
            throw new InvalidArgumentException("Cannot read file: {$path}");
        }

        return $this->scanSource($source, $path);
    }

    /**
     * Scans every `.php` file below the directory, in path order, so reports are stable.
     *
     * @return list<ForbiddenReference>
     */
    public function scanDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException("Not a directory: {$directory}");
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        $hits = [];
        foreach ($files as $file) {
            $hits = [...$hits, ...$this->scanFile($file)];
        }

        return $hits;
    }

    /**
     * @param array{int, string, int}|string|null $next the token after this one
     *
     * @return list<array{string, string, int}> rule, matched text and byte offset, in source order
     */
    private function matchesIn(int $id, string $text, array|string|null $next): array
    {
        return match ($id) {
            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED => $this->nameMatches($text),
            T_STRING => $this->groupUseMatches($text, $next),
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE => $this->sortedByOffset([
                ...$this->regexMatches(self::CLASS_IN_TEXT, self::RULE_CLASS, $text),
                ...$this->regexMatches(self::ENV_IN_STRING, self::RULE_ENV, $text),
            ]),
            T_DOC_COMMENT => $this->regexMatches(self::CLASS_IN_TEXT, self::RULE_CLASS, $text),
            default => [],
        };
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function nameMatches(string $name): array
    {
        return preg_match('/^\\\\?Bref\\\\/i', $name) === 1 ? [[self::RULE_CLASS, $name, 0]] : [];
    }

    /**
     * `use Bref\{A, B};` tokenises as T_STRING `Bref` followed by T_NS_SEPARATOR.
     *
     * @param array{int, string, int}|string|null $next
     *
     * @return list<array{string, string, int}>
     */
    private function groupUseMatches(string $text, array|string|null $next): array
    {
        $followedBySeparator = is_array($next) && $next[0] === T_NS_SEPARATOR;

        return $followedBySeparator && strcasecmp($text, 'Bref') === 0
            ? [[self::RULE_CLASS, $text . '\\', 0]]
            : [];
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function regexMatches(string $pattern, string $rule, string $text): array
    {
        if (preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        return array_map(
            static fn(array $match): array => [$rule, $match[0], $match[1]],
            $found[0],
        );
    }

    /**
     * @param list<array{string, string, int}> $matches
     *
     * @return list<array{string, string, int}>
     */
    private function sortedByOffset(array $matches): array
    {
        usort($matches, static fn(array $a, array $b): int => $a[2] <=> $b[2]);

        return $matches;
    }
}
