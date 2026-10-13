<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Problem\HttpProblem;
use JsonException;

/**
 * Strict reader of a JSON request body. The rules, in this order:
 *
 *  - Content-Type must be `application/json`, optionally with `charset=utf-8` as the only parameter
 *    (case-insensitive), else 415. Nothing else is guessed or sniffed.
 *  - More than {@see self::MAX_BYTES} bytes gives 413.
 *  - Invalid JSON or invalid UTF-8, a number too large for a float (1e999), nesting of {@see self::MAX_DEPTH}
 *    levels or more (json_decode's depth: 31 nested objects/arrays pass, 32 do not), or a document that is not
 *    an object (array, scalar, null, empty body) gives 400.
 *
 * Integers beyond 64 bits are kept as strings (JSON_BIGINT_AS_STRING) so no precision is lost silently; callers
 * validate types. Duplicate keys: the last one wins. Nested empty objects and empty arrays both decode to [].
 * Problem details never echo the input.
 */
final class JsonBody
{
    public const MAX_BYTES = 65536;
    public const MAX_DEPTH = 32;

    private const MEDIA_TYPE = 'application/json';
    private const ALLOWED_PARAMETER = 'charset=utf-8';
    private const BLANKS = " \t";
    private const JSON_WHITESPACE = " \t\n\r";

    /**
     * @return array<array-key, mixed>
     *
     * @throws HttpProblem
     */
    public static function decode(?string $contentType, string $body): array
    {
        if (!self::isJson($contentType)) {
            throw HttpProblem::of(415, 'The request body must be application/json.');
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw HttpProblem::of(413, 'The request body is larger than ' . self::MAX_BYTES . ' bytes.');
        }

        try {
            $decoded = json_decode($body, true, self::MAX_DEPTH, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw HttpProblem::of(400, 'The request body is not valid JSON.', $exception);
        }

        // Valid JSON is an object exactly when its first significant character is "{" ([] and {} both decode to []).
        if (!is_array($decoded) || ltrim($body, self::JSON_WHITESPACE)[0] !== '{') {
            throw HttpProblem::of(400, 'The request body must be a JSON object.');
        }

        if (self::hasNonFiniteFloat($decoded)) {
            throw HttpProblem::of(400, 'The request body contains a number out of range.');
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private static function hasNonFiniteFloat(array $decoded): bool
    {
        $found = false;
        array_walk_recursive($decoded, static function (mixed $value) use (&$found): void {
            $found = $found || (is_float($value) && !is_finite($value));
        });

        return $found;
    }

    private static function isJson(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $parts = explode(';', strtolower($contentType));
        $mediaType = trim(array_shift($parts), self::BLANKS);
        if ($mediaType !== self::MEDIA_TYPE) {
            return false;
        }

        return match (count($parts)) {
            0 => true,
            1 => trim($parts[0], self::BLANKS) === self::ALLOWED_PARAMETER,
            default => false,
        };
    }
}
