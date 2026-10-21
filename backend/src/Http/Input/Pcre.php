<?php

declare(strict_types=1);

namespace App\Http\Input;

use RuntimeException;

/**
 * PCRE calls that fail closed. `preg_match` and `preg_replace` report an error (invalid UTF-8, a backtrack or JIT
 * limit) as `false` or `null`, which a bare `=== 1` or `?? ''` would read as "clean" or "empty". Here an error throws,
 * with PCRE's message and never the subject, so a failed check can never let a text through.
 */
final class Pcre
{
    public static function matches(string $pattern, string $subject): bool
    {
        $result = preg_match($pattern, $subject);
        if ($result === false) {
            throw new RuntimeException('A text check failed: ' . preg_last_error_msg());
        }

        return $result === 1;
    }

    public static function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);
        if ($result === null) {
            throw new RuntimeException('A text rewrite failed: ' . preg_last_error_msg());
        }

        return $result;
    }
}
