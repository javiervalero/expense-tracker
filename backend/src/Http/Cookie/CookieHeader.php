<?php

declare(strict_types=1);

namespace App\Http\Cookie;

/**
 * A strict parser for the request `Cookie` header. Bref's own `getCookies()` is never used: it keeps only the last
 * duplicate and URL-decodes values (R2), and a duplicated session cookie must stay visible.
 *
 * Pairs are split on `;`, with spaces and tabs trimmed around each. A name must be an RFC 6265 token (and so is
 * case-sensitive); a pair with no `=` or an invalid name is skipped. The value is everything after the first `=`,
 * kept raw: no URL-decoding, quotes kept. Duplicate names are all returned, in order. A header over
 * {@see self::MAX_BYTES} parses as empty.
 */
final class CookieHeader
{
    public const MAX_BYTES = 8192;

    private const TOKEN = "/\\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\\z/D";
    private const TRIM = " \t";

    /**
     * @return list<array{name: string, value: string}>
     */
    public static function parse(#[\SensitiveParameter] string $header): array
    {
        if (strlen($header) > self::MAX_BYTES) {
            return [];
        }
        $pairs = [];
        foreach (explode(';', $header) as $segment) {
            $pair = self::pair(trim($segment, self::TRIM));
            if ($pair !== null) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * @return array{name: string, value: string}|null
     */
    private static function pair(#[\SensitiveParameter] string $segment): ?array
    {
        $separator = strpos($segment, '=');
        if ($separator === false) {
            return null;
        }
        $name = substr($segment, 0, $separator);
        if (preg_match(self::TOKEN, $name) !== 1) {
            return null;
        }

        return ['name' => $name, 'value' => substr($segment, $separator + 1)];
    }
}
