<?php

declare(strict_types=1);

namespace LocalAws\Support;

/**
 * The one place that decides which endpoints this tooling may talk to.
 *
 * Safety by construction: an endpoint must be a local stand-in (loopback, the Docker host alias or a single-label
 * Compose service name) and never real AWS, so a wrong `.env` can never reach (or bill) an account.
 *
 * KEEP IN SYNC: the allowlist (LOCAL_HOSTS, COMPOSE_SERVICE_NAME, REAL_AWS_SUFFIXES) mirrors the ones in
 * backend/src/Infrastructure/Config/LocalEndpoint.php and backend/tests/Support/LocalAws.php (all three list each
 * other). They are separate on purpose (separate Composer projects); a change to one must be made in the others.
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
     * @param string $variable name of the setting, used in the error message
     *
     * @throws \RuntimeException when the URL is malformed, hides its host, points at real AWS or is not local
     */
    public static function assertAllowed(string $variable, string $endpoint): void
    {
        $parts = parse_url($endpoint);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if ($host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            throw new \RuntimeException(sprintf('%s is not an http(s) URL with a host.', $variable));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException(sprintf('%s must not contain credentials (user@host): the real host could be hidden.', $variable));
        }

        $normalisedHost = strtolower(rtrim($host, '.'));
        foreach (self::REAL_AWS_SUFFIXES as $suffix) {
            if ($normalisedHost === ltrim($suffix, '.') || str_ends_with($normalisedHost, $suffix)) {
                throw new \RuntimeException(sprintf('%s points at real AWS (%s); this tooling only talks to local stand-ins.', $variable, $host));
            }
        }
        if (!in_array($normalisedHost, self::LOCAL_HOSTS, true) && preg_match(self::COMPOSE_SERVICE_NAME, $normalisedHost) !== 1) {
            throw new \RuntimeException(sprintf(
                '%s host "%s" is not an allowed local stand-in (localhost, loopback, host.docker.internal or a Compose service name).',
                $variable,
                $host,
            ));
        }
    }
}
