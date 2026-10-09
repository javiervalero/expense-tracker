<?php

declare(strict_types=1);

namespace LocalAws\Relay;

/**
 * The path of a request target, taken raw (everything before the first `?`) and never normalised: `//x` stays
 * `//x`, so it is not mistaken for `/` (as parse_url would do, reading `//x` as a host).
 */
final class RequestPath
{
    public static function of(string $requestUri): string
    {
        // Not strtok(): it skips leading delimiters, so `?a=b` would come back as `a=b`.
        $query = strpos($requestUri, '?');
        $path = $query === false ? $requestUri : substr($requestUri, 0, $query);

        return $path === '' ? '/' : $path;
    }
}
