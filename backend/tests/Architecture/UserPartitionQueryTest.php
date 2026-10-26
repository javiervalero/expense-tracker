<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 (B10, Phase 2 WP7 notes): a user's partition holds the `PROFILE` item next to their data, so every query on
 * it must restrict the sort key and can never return `PROFILE`. And the partition key `USER#<id>` has one builder,
 * `AuthKeys::userPartition()`, which validates the id; no other file under src/ may spell the literal.
 *
 * It tokenises with PhpToken. Rule 1: every `->query(` or `?->query(` call is checked on its key condition itself:
 * the first argument (ItemStore::query), or, when the first argument is an array literal, the value of its
 * `'KeyConditionExpression'` key. That value must be one plain string literal containing `begins_with(SK,` or
 * matching `\b(SK|LSI1SK) BETWEEN\b`, case-sensitive. Other literals in the call (a FilterExpression, a message) do not
 * count; a variable, a concatenation or an interpolated string in that position fails, on purpose: write the literal
 * in the call. Rule 2: any string literal (quoted, interpolated or heredoc) that contains `USER#` is flagged, after
 * decoding the escapes of double-quoted text (`"USER\x23"`, `"USER\043"`, `"USER\u{23}"`). Rule 2 does not apply to
 * AuthKeys.php, which is the one place that builds it; rule 1 applies to every file. Comments are ignored.
 *
 * Accepted limits, left to review: dynamic method names (`$o->$method()`), a literal that applies `begins_with(` to
 * the wrong attribute value, and a prefix spelled in pieces (`'USER' . '#'`). If a thin wrapper around the SDK's query
 * is ever needed, give it an explicit, commented exclusion here; do not weaken the rule. SdkOperationsTest keeps the
 * other SDK read operations inside ItemStore.
 */
final class UserPartitionQueryTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/userpartition';
    private const STRING_TOKENS = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
    private const KEY_CONDITION_KEY = 'KeyConditionExpression';
    private const RESTRICTED_SORT_KEY = '/begins_with\(SK,|\b(?:SK|LSI1SK) BETWEEN\b/';
    private const QUERY_LEAK = '->query( without a begins_with(SK, or SK BETWEEN key condition literal';
    private const USER_LEAK = 'literal USER#';

    public function testCleanFixtureHasNoLeak(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php'));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';
        $query = self::QUERY_LEAK;
        $user = self::USER_LEAK;

        self::assertSame([
            "{$file}:11: {$query}",
            "{$file}:12: {$query}",
            "{$file}:13: {$query}",
            "{$file}:17: {$query}",
            "{$file}:18: {$user}",
            "{$file}:19: {$user}",
            "{$file}:21: {$user}",
            "{$file}:24: {$query}",
            "{$file}:28: {$query}",
            "{$file}:29: {$query}",
            "{$file}:30: {$query}",
            "{$file}:31: {$query}",
            "{$file}:32: {$query}",
            "{$file}:33: {$user}",
            "{$file}:34: {$user}",
            "{$file}:35: {$user}",
        ], $this->scanFile($file));
    }

    public function testSourceDirectoryExists(): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/src', 'The guard scans src/ but it does not exist.');
    }

    public function testEveryQueryInSrcIsRestrictedAndOnlyAuthKeysSpellsTheUserPartition(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $authKeys = $root . '/Auth/AuthKeys.php';
        self::assertFileExists($authKeys);
        $hits = [];
        foreach (TokenScanner::phpFiles($root) as $path) {
            $found = $this->scanFile($path);
            $hits = [...$hits, ...($path === $authKeys ? $this->withoutUserLiterals($found) : $found)];
        }

        self::assertSame([], $hits, 'Query a user partition with a begins_with(SK, or SK BETWEEN literal key condition, and build USER# only in AuthKeys::userPartition().');
    }

    public function testAuthKeysSpellsTheUserPartitionExactlyOnce(): void
    {
        $authKeys = dirname(__DIR__, 2) . '/src/Auth/AuthKeys.php';

        $literals = array_filter($this->scanFile($authKeys), static fn(string $hit): bool => str_ends_with($hit, self::USER_LEAK));

        self::assertCount(1, $literals, 'AuthKeys::userPartition() is the one place USER# is built.');
    }

    /**
     * @return list<string> "file:line: label" for each leak, in source order
     */
    private function scanFile(string $path): array
    {
        $tokens = TokenScanner::tokens($path);
        $hits = [];
        foreach ($tokens as $index => $token) {
            $label = $this->label($tokens, $index);
            if ($label !== null) {
                $hits[] = "{$path}:{$token->line}: {$label}";
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $hits
     *
     * @return list<string>
     */
    private function withoutUserLiterals(array $hits): array
    {
        return array_values(array_filter($hits, static fn(string $hit): bool => !str_ends_with($hit, self::USER_LEAK)));
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function label(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];
        if ($token->is(self::STRING_TOKENS)) {
            return str_contains(self::decoded($token), 'USER#') ? self::USER_LEAK : null;
        }
        if (!$token->is(T_STRING) || strtolower($token->text) !== 'query') {
            return null;
        }
        $previous = TokenScanner::neighbour($tokens, $index, -1);
        $isCall = $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) === true
            && TokenScanner::neighbour($tokens, $index, 1)?->text === '(';
        if (!$isCall || $this->keyConditionIsRestricted($tokens, $index)) {
            return null;
        }

        return self::QUERY_LEAK;
    }

    /** The text a string token stands for: double-quoted and heredoc escapes are decoded, single-quoted text is not. */
    private static function decoded(\PhpToken $token): string
    {
        $text = $token->text;
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            if ($text[0] !== '"') {
                return substr($text, 1, -1);
            }
            $text = substr($text, 1, -1);
        }
        $text = preg_replace_callback(
            '/\\\\u\{([0-9a-fA-F]+)\}/',
            static fn(array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8'),
            $text,
        ) ?? $text;

        return stripcslashes($text);
    }

    /**
     * @param list<\PhpToken> $tokens index of the `query` name
     */
    private function keyConditionIsRestricted(array $tokens, int $nameIndex): bool
    {
        $argument = $this->firstArgument($tokens, $nameIndex);
        $condition = ($argument[0] ?? null)?->text === '[' ? $this->keyConditionValue($argument) : $argument;
        if (count($condition) !== 1 || !$condition[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
            return false;
        }

        return preg_match(self::RESTRICTED_SORT_KEY, self::decoded($condition[0])) === 1;
    }

    /**
     * The significant tokens of the first argument of the call whose name is at the index.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return list<\PhpToken>
     */
    private function firstArgument(array $tokens, int $nameIndex): array
    {
        $argument = [];
        $depth = 0;
        for ($i = $nameIndex + 2; isset($tokens[$i]); $i++) {
            $token = $tokens[$i];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if (in_array($token->text, [')', ']', '}'], true) && --$depth < 0) {
                break;
            }
            if ($token->text === ',' && $depth === 0) {
                break;
            }
            if (in_array($token->text, ['(', '[', '{'], true)) {
                $depth++;
            }
            $argument[] = $token;
        }

        return $argument;
    }

    /**
     * The value tokens of the top-level `'KeyConditionExpression' =>` entry of an array literal (empty if absent).
     *
     * @param list<\PhpToken> $array the tokens of the literal, `[` first
     *
     * @return list<\PhpToken>
     */
    private function keyConditionValue(array $array): array
    {
        $depth = 0;
        foreach ($array as $i => $token) {
            if (in_array($token->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            }
            $isKey = $depth === 1 && $token->is(T_CONSTANT_ENCAPSED_STRING) && self::decoded($token) === self::KEY_CONDITION_KEY
                && ($array[$i + 1] ?? null)?->is(T_DOUBLE_ARROW) === true;
            if ($isKey) {
                return $this->entryValue($array, $i + 2);
            }
        }

        return [];
    }

    /**
     * @param list<\PhpToken> $array
     *
     * @return list<\PhpToken> the tokens from the index to the next top-level comma or the closing bracket
     */
    private function entryValue(array $array, int $start): array
    {
        $value = [];
        $depth = 0;
        for ($i = $start; isset($array[$i]); $i++) {
            $text = $array[$i]->text;
            if (in_array($text, [')', ']', '}'], true) && --$depth < 0) {
                break;
            }
            if ($text === ',' && $depth === 0) {
                break;
            }
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            }
            $value[] = $array[$i];
        }

        return $value;
    }
}
