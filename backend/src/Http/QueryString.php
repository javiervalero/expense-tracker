<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Problem\HttpProblem;

/**
 * Strict parser of a raw query string into a flat `name => value` map. It deliberately is not `parse_str`
 * (no nested arrays, no key mangling). Rules, every violation a 400 problem whose detail never echoes input:
 *
 *  - pairs are separated by "&", name and value by the first "="; empty segments ("a=1&&b=2") are skipped;
 *    a name without "=" has the value "";
 *  - decoding is application/x-www-form-urlencoded: "+" is a space (an encoded plus is "%2B"), "%XX" is a byte
 *    (hex in either case), decoded once only. A "%" not followed by two hex digits is invalid;
 *  - the decoded name and value must be valid UTF-8 and contain no NUL byte;
 *  - a name cannot be empty and cannot contain "[" or "]" (so no `a[]` or `a[x]`, raw or encoded);
 *  - a name that appears twice (after decoding) is an error: there are no multi-value parameters;
 *  - at most {@see self::MAX_PAIRS} pairs.
 *
 * These guarantees hold for a RAW query string, which is what PHP behind nginx receives locally. On Lambda, Bref
 * first rebuilds the query string from the event (parse_str, then http_build_query), so what reaches this parser
 * is already normalised: duplicates collapse (last wins), an invalid escape becomes a literal "%", an empty
 * name disappears, repeats turn into `a[0]`, `a[1]`. `QueryStringBrefTest` pins the exact outputs. Handlers must
 * therefore validate their own parameters (type, range, allowed values) and never rely on this parser alone.
 * Names or values that are all digits become integer array keys (`?1=x`), hence the `array-key` return type.
 */
final class QueryString
{
    public const MAX_PAIRS = 100;

    private const INVALID_ESCAPE = '/%(?![0-9A-Fa-f]{2})/';

    /**
     * @return array<array-key, string>
     *
     * @throws HttpProblem
     */
    public static function parse(string $raw): array
    {
        $result = [];
        $pairs = 0;
        foreach (explode('&', $raw) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (++$pairs > self::MAX_PAIRS) {
                throw self::invalid('The query string has too many parameters.');
            }
            [$name, $value] = self::pair($segment);
            if (array_key_exists($name, $result)) {
                throw self::invalid('A query parameter is repeated; each may appear once.');
            }
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * @return array{string, string}
     */
    private static function pair(string $segment): array
    {
        $parts = explode('=', $segment, 2);
        $name = self::decode($parts[0]);
        if ($name === '') {
            throw self::invalid('A query parameter has an empty name.');
        }
        if (strpbrk($name, '[]') !== false) {
            throw self::invalid('Query parameter names cannot contain "[" or "]".');
        }

        return [$name, self::decode($parts[1] ?? '')];
    }

    private static function decode(string $component): string
    {
        if (preg_match(self::INVALID_ESCAPE, $component) === 1) {
            throw self::invalid('The query string has an invalid percent-encoding.');
        }

        $decoded = rawurldecode(str_replace('+', ' ', $component));
        if (preg_match('//u', $decoded) !== 1 || str_contains($decoded, "\0")) {
            throw self::invalid('The query string is not valid UTF-8 text.');
        }

        return $decoded;
    }

    private static function invalid(string $detail): HttpProblem
    {
        return HttpProblem::of(400, $detail);
    }
}
