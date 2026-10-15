<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * Decides which endpoints the backend may be pointed at through configuration.
 *
 * Safety by construction: an endpoint override must be a local stand-in (loopback, the Docker host alias or a
 * single-label Compose service name) and never real AWS, so a wrong `.env` can never send requests (or bill) an
 * account. Reasons never contain the endpoint itself: it may hold credentials.
 *
 * KEEP IN SYNC: the allowlist (LOCAL_HOSTS, COMPOSE_SERVICE_NAME, REAL_AWS_SUFFIXES) mirrors
 * docker/local-aws/src/Support/LocalEndpoint.php and backend/tests/Support/LocalAws.php. They are separate on purpose
 * (separate Composer projects); a change to one must be made in the others.
 */
final class LocalEndpoint
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', 'host.docker.internal'];
    /**
     * Must start with a letter: an all-digit or hex single label (`2130706433`, `0x7f000001`) is an IPv4 literal to
     * the resolver, not a Compose service name, and would slip through as one.
     */
    private const COMPOSE_SERVICE_NAME = '/^[a-z][a-z0-9-]*\z/';
    private const REAL_AWS_SUFFIXES = ['.amazonaws.com', '.amazonaws.com.cn', '.api.aws'];

    /**
     * @return string|null why the endpoint is refused (never including its value), or null when it is allowed
     */
    public static function violation(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if (!is_array($parts) || $host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            return 'is not an http(s) URL with a host';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'must not contain credentials (user@host)';
        }

        $normalisedHost = strtolower(rtrim($host, '.'));
        foreach (self::REAL_AWS_SUFFIXES as $suffix) {
            if ($normalisedHost === ltrim($suffix, '.') || str_ends_with($normalisedHost, $suffix)) {
                return 'points at real AWS';
            }
        }
        if (!in_array($normalisedHost, self::LOCAL_HOSTS, true) && preg_match(self::COMPOSE_SERVICE_NAME, $normalisedHost) !== 1) {
            return 'is not a local stand-in (localhost, loopback, host.docker.internal or a Compose service name)';
        }

        return null;
    }
}
