<?php

declare(strict_types=1);

namespace App\Auth\Session;

use App\Http\Cookie\CookieHeader;
use App\Http\Request;
use InvalidArgumentException;

/**
 * The session cookie (A1, A20). Attributes are always `Max-Age`, `Path=/`, `Secure`, `HttpOnly`, `SameSite=Lax`,
 * in that order, with no `Domain`; the same string is used locally and on AWS. The `__Host-` prefix makes the
 * browser enforce `Secure`, `Path=/` and no `Domain`. `Max-Age` is the absolute session lifetime, so renewing
 * the session never needs a new Set-Cookie. Logging of the duplicate case belongs to the caller.
 */
final class SessionCookie
{
    public const NAME = '__Host-session';

    private const ATTRIBUTES = 'Path=/; Secure; HttpOnly; SameSite=Lax';

    /** @throws InvalidArgumentException when $maxAge is not a positive number of seconds */
    public static function issue(SessionToken $token, int $maxAge): string
    {
        if ($maxAge < 1) {
            throw new InvalidArgumentException('The session cookie lifetime must be a positive number of seconds.');
        }

        return self::build($token->cookieValue(), $maxAge);
    }

    /** Expires the cookie in the browser: an empty value and `Max-Age=0`. */
    public static function clear(): string
    {
        return self::build('', 0);
    }

    public static function read(Request $request): SessionCookieRead
    {
        $header = $request->header('cookie');
        if ($header === null) {
            return SessionCookieRead::absent();
        }
        $values = [];
        foreach (CookieHeader::parse($header) as $pair) {
            if ($pair['name'] === self::NAME) {
                $values[] = $pair['value'];
            }
        }
        if ($values === []) {
            return SessionCookieRead::absent();
        }
        if (count($values) > 1) {
            return SessionCookieRead::duplicate();
        }
        $token = SessionToken::fromCookieValue($values[0]);

        return $token === null ? SessionCookieRead::malformed() : SessionCookieRead::present($token);
    }

    private static function build(#[\SensitiveParameter] string $value, int $maxAge): string
    {
        return self::NAME . '=' . $value . '; Max-Age=' . $maxAge . '; ' . self::ATTRIBUTES;
    }
}
