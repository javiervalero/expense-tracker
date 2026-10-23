<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Support;

use PHPUnit\Framework\Assert;

/**
 * Helpers shared by the token-based architecture guards: tokenising a file, walking src/, and looking at the
 * neighbouring significant token or the last segment of a name.
 */
final class TokenScanner
{
    public const NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /**
     * @return list<\PhpToken>
     */
    public static function tokens(string $path): array
    {
        $source = file_get_contents($path);
        Assert::assertIsString($source, $path);

        return array_values(\PhpToken::tokenize($source));
    }

    /**
     * @return list<string> every .php file under the directory except the excluded one
     */
    public static function phpFilesExcept(string $root, string $excluded): array
    {
        return array_values(array_filter(self::phpFiles($root), static fn(string $path): bool => $path !== $excluded));
    }

    /**
     * @return list<string> every .php file under the directory
     */
    public static function phpFiles(string $root): array
    {
        $paths = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $paths[] = $entry->getPathname();
            }
        }

        return $paths;
    }

    /**
     * The nearest token before (step -1) or after (step 1) the index that is not whitespace or a comment.
     *
     * @param list<\PhpToken> $tokens
     */
    public static function neighbour(array $tokens, int $index, int $step): ?\PhpToken
    {
        for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
            if (!$tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /** The last segment of a plain, qualified or fully qualified name, lower-cased. */
    public static function lastSegment(string $name): string
    {
        $name = strtolower($name);

        return substr($name, (int) strrpos('\\' . $name, '\\'));
    }
}
