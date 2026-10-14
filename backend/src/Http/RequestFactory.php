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
 *    `REMOTE_ADDR` or environment variables, is ignored;
 *  - the content headers come ONLY from `CONTENT_TYPE` / `CONTENT_LENGTH`: `HTTP_CONTENT_TYPE` and
 *    `HTTP_CONTENT_LENGTH` (a client-sent "Content-Type" header cannot reach them in CGI, but an underscore
 *    spelling could) are ignored;
 *  - only the canonical spelling is a header: `HTTP_` followed by upper-case letters, digits and "_" (what nginx
 *    and Bref produce). Any other spelling (`HTTP_x_a`, `HTTP_X-A`) is ignored, so two variables can never
 *    map to the same header name and the result does not depend on their order in `$_SERVER`;
 *  - headers whose names differ only in "-" versus "_" (`X-Foo`, `X_Foo`) are the same variable and cannot be told
 *    apart here. On AWS, underscore headers do not get through because CloudFront only forwards the allow-listed
 *    headers of the origin request policy, and the origin check rejects anything not coming from CloudFront;
 *    locally nginx ignores them by default (`underscores_in_headers off`).
 */
final class RequestFactory
{
    private const HEADER_PREFIX = 'HTTP_';
    private const CONTENT_HEADERS = ['CONTENT_TYPE', 'CONTENT_LENGTH'];
    private const CANONICAL_HTTP_VARIABLE = '/^HTTP_[A-Z0-9][A-Z0-9_]*$/D';
    private const HTTP_CONTENT_VARIABLES = ['HTTP_CONTENT_TYPE', 'HTTP_CONTENT_LENGTH'];
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
     * Only the method and path (nothing that can be malformed): enough to log a request that could not be built.
     *
     * @param array<array-key, mixed> $server
     */
    public static function bare(array $server): Request
    {
        return new Request(
            self::string($server, 'REQUEST_METHOD'),
            self::pathOf(self::string($server, 'REQUEST_URI')),
            [],
            [],
            '',
        );
    }

    /**
     * @param array<array-key, mixed> $server
     *
     * @return array<array-key, string>
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
        if (
            preg_match(self::CANONICAL_HTTP_VARIABLE, $variable) === 1
            && !in_array($variable, self::HTTP_CONTENT_VARIABLES, true)
        ) {
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
