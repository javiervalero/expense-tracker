<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Problem\HttpProblem;

/**
 * Builds a {@see Request} from the `$_SERVER` array and the raw body (both read by the caller, so this stays
 * pure). Only what the API needs is taken from `$_SERVER`:
 *
 *  - `REQUEST_METHOD`, and the path of `REQUEST_URI` (raw: cut at the first "?" or "#", never decoded);
 *  - `QUERY_STRING`, parsed strictly by {@see QueryString} (an invalid one is a 400);
 *  - headers: `HTTP_*` variables (lower-cased, "_" becomes "-") plus `CONTENT_TYPE` and `CONTENT_LENGTH`
 *    when not empty. String values only. `HTTP_PROXY` is dropped (httpoxy). Everything else, such as
 *    `REMOTE_ADDR` or environment variables, is ignored. Headers whose names differ only in "-" versus "_"
 *    cannot be told apart in `$_SERVER`; the front proxy is expected to drop underscore headers.
 */
final class RequestFactory
{
    private const HEADER_PREFIX = 'HTTP_';
    private const CONTENT_HEADERS = ['CONTENT_TYPE', 'CONTENT_LENGTH'];
    private const DROPPED_HEADERS = ['proxy'];

    /**
     * @param array<array-key, mixed> $server
     *
     * @throws HttpProblem
     */
    public static function fromGlobals(array $server, string $body): Request
    {
        return new Request(
            self::string($server, 'REQUEST_METHOD'),
            self::pathOf(self::string($server, 'REQUEST_URI')),
            QueryString::parse(self::string($server, 'QUERY_STRING')),
            self::headers($server),
            $body,
        );
    }

    /**
     * @param array<array-key, mixed> $server
     *
     * @return array<string, string>
     */
    private static function headers(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            $name = self::headerName((string) $key);
            if ($name === null || !is_string($value) || in_array($name, self::DROPPED_HEADERS, true)) {
                continue;
            }
            if ($value === '' && in_array($key, self::CONTENT_HEADERS, true)) {
                continue;
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    private static function headerName(string $variable): ?string
    {
        if (in_array($variable, self::CONTENT_HEADERS, true)) {
            return self::normalise($variable);
        }
        if (str_starts_with($variable, self::HEADER_PREFIX) && strlen($variable) > strlen(self::HEADER_PREFIX)) {
            return self::normalise(substr($variable, strlen(self::HEADER_PREFIX)));
        }

        return null;
    }

    private static function normalise(string $name): string
    {
        return strtolower(str_replace('_', '-', $name));
    }

    private static function pathOf(string $uri): string
    {
        return substr($uri, 0, strcspn($uri, '?#'));
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function string(array $server, string $key): string
    {
        $value = $server[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
