<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

use App\Infrastructure\DynamoDb\DynamoDbSettings;

/**
 * The API's configuration, read once from the process environment by the composition root. It grows with the
 * work packages that need settings (client IP header, rate limits, table names). Every problem is collected before
 * failing, and only variable names are reported, never values.
 */
final readonly class ApiConfig
{
    private const ORIGIN_VERIFY_SECRETS = 'ORIGIN_VERIFY_SECRETS';
    private const MIN_SLOT_LENGTH = 32;
    private const MAX_SLOTS = 2;
    private const CLIENT_IP_HEADER = 'CLIENT_IP_HEADER';
    private const APP_ENV = 'APP_ENV';
    private const LOCAL_ENV = 'local';
    private const DYNAMODB_ENDPOINT = 'DYNAMODB_ENDPOINT';
    private const APP_ORIGINS = 'APP_ORIGINS';
    private const MAX_ORIGINS = 3;
    private const MAX_PORT = 65535;
    /** Browsers never send the default port in an `Origin`, so such an entry could never match. */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];
    private const PLAIN_HTTP_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Exactly `scheme://host[:port]`, lower case: no path, query, fragment, credentials or trailing slash. The host is
     * a DNS name (labels of at most 63 characters), an IPv4 address or `[::1]`. A port never starts with 0.
     */
    private const ORIGIN_PATTERN = '/\A(https?):\/\/((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)|\[::1\])(?::([1-9][0-9]{0,4}))?\z/';

    /**
     * The only header that may carry the caller's address, in every environment (ADR 0012 section 4.3). Any other
     * name (Cookie, Authorization, X-Origin-Verify, the underscore spelling) would make the client choose its address.
     */
    private const VIEWER_ADDRESS_HEADER = 'CloudFront-Viewer-Address';

    /**
     * One slot: visible ASCII only (no space, no control character), without `"` and `\` (which JSON escapes, and
     * would make the secret look different in an encoded log line), long enough to resist guessing.
     */
    private const SLOT_PATTERN = '/\A[\x21\x23-\x5B\x5D-\x7E]{' . self::MIN_SLOT_LENGTH . ',}\z/';

    /**
     * @param list<string> $originVerifySecrets the accepted `X-Origin-Verify` values (slot A, then B for a rotation)
     * @param string $clientIpHeader the one header that carries the caller's address (always `CloudFront-Viewer-Address`)
     * @param list<string> $appOrigins the browser origins allowed to make state-changing calls (1 to 3, exact strings)
     * @param DynamoDbSettings $dynamoDb where the data lives
     */
    private function __construct(
        public array $originVerifySecrets,
        public string $clientIpHeader,
        public array $appOrigins,
        public DynamoDbSettings $dynamoDb,
    ) {}

    /**
     * @param array<array-key, mixed> $env
     *
     * @throws InvalidConfiguration
     */
    public static function fromEnvironment(array $env): self
    {
        $errors = [];
        $secrets = self::originVerifySecrets($env, $errors);
        $clientIpHeader = self::clientIpHeader($env, $errors);
        $appOrigins = self::appOrigins($env, $errors);
        $dynamoDb = self::dynamoDb($env, $errors);
        sort($errors);
        if ($errors !== [] || $dynamoDb === null) {
            throw new InvalidConfiguration($errors);
        }

        return new self($secrets, $clientIpHeader, $appOrigins, $dynamoDb);
    }

    /**
     * Its errors join the others. With `APP_ENV=local` an endpoint is mandatory, so an empty value can never
     * silently mean real AWS (the in-app twin of `make check-env`).
     *
     * @param array<array-key, mixed> $env
     * @param list<string> $errors
     * @param-out list<string> $errors
     */
    private static function dynamoDb(array $env, array &$errors): ?DynamoDbSettings
    {
        try {
            $settings = DynamoDbSettings::fromEnvironment($env);
        } catch (InvalidConfiguration $invalid) {
            array_push($errors, ...$invalid->errors);
            $settings = null;
        }
        $endpoint = $env[self::DYNAMODB_ENDPOINT] ?? null;
        $appEnv = $env[self::APP_ENV] ?? '';
        $isLocal = is_string($appEnv) && strtolower(trim($appEnv)) === self::LOCAL_ENV;
        if ($isLocal && ($endpoint === null || (is_string($endpoint) && trim($endpoint) === ''))) {
            $errors[] = self::DYNAMODB_ENDPOINT . ' is required when ' . self::APP_ENV . ' is ' . self::LOCAL_ENV;
        }

        return $settings;
    }

    /**
     * @param array<array-key, mixed> $env
     * @param list<string> $errors
     * @param-out list<string> $errors
     *
     * @return list<string>
     */
    private static function appOrigins(array $env, array &$errors): array
    {
        $name = self::APP_ORIGINS;
        if (!array_key_exists($name, $env)) {
            $errors[] = $name . ' is missing';

            return [];
        }
        $value = $env[$name];
        if (!is_string($value) || $value === '') {
            $errors[] = $name . ' must be a non-empty string';

            return [];
        }
        $origins = explode(',', $value);
        if (count($origins) > self::MAX_ORIGINS || count(array_unique($origins)) !== count($origins)) {
            $errors[] = $name . ' must hold 1 to ' . self::MAX_ORIGINS . ' different origins';

            return [];
        }
        foreach ($origins as $origin) {
            if (!self::isValidOrigin($origin)) {
                $errors[] = $name . ' must hold comma-separated origins, each exactly scheme://host[:port] in lower case '
                    . '(https, except for localhost, 127.0.0.1 and [::1])';

                return [];
            }
        }

        return $origins;
    }

    private static function isValidOrigin(string $origin): bool
    {
        if (preg_match(self::ORIGIN_PATTERN, $origin, $parts) !== 1) {
            return false;
        }
        $port = $parts[3] ?? '';
        if ($port !== '' && ((int) $port > self::MAX_PORT || (int) $port === self::DEFAULT_PORTS[$parts[1]])) {
            return false;
        }

        return $parts[1] === 'https' || in_array($parts[2], self::PLAIN_HTTP_HOSTS, true);
    }

    /**
     * @param array<array-key, mixed> $env
     * @param list<string> $errors
     * @param-out list<string> $errors
     */
    private static function clientIpHeader(array $env, array &$errors): string
    {
        $name = self::CLIENT_IP_HEADER;
        if (!array_key_exists($name, $env)) {
            $errors[] = $name . ' is missing';

            return '';
        }
        $value = $env[$name];
        if (!is_string($value) || strcasecmp($value, self::VIEWER_ADDRESS_HEADER) !== 0) {
            $errors[] = $name . ' must be the CloudFront viewer address header';

            return '';
        }

        return self::VIEWER_ADDRESS_HEADER;
    }

    /**
     * @param array<array-key, mixed> $env
     * @param list<string> $errors
     * @param-out list<string> $errors
     *
     * @return list<string>
     */
    private static function originVerifySecrets(array $env, array &$errors): array
    {
        $name = self::ORIGIN_VERIFY_SECRETS;
        if (!array_key_exists($name, $env)) {
            $errors[] = $name . ' is missing';

            return [];
        }
        $value = $env[$name];
        if (!is_string($value)) {
            $errors[] = $name . ' is not a string';

            return [];
        }
        if (trim($value) === '') {
            $errors[] = $name . ' is empty';

            return [];
        }
        $slots = explode(',', $value);
        if (count($slots) > self::MAX_SLOTS) {
            $errors[] = $name . ' has more than ' . self::MAX_SLOTS . ' slots';

            return [];
        }
        if (count(array_unique($slots)) !== count($slots)) {
            $errors[] = $name . ' has the same slot twice';

            return [];
        }
        if (count($slots) === 2 && (str_contains($slots[0], $slots[1]) || str_contains($slots[1], $slots[0]))) {
            $errors[] = $name . ' has one slot inside the other';

            return [];
        }
        foreach ($slots as $slot) {
            if (preg_match(self::SLOT_PATTERN, $slot) !== 1) {
                $errors[] = $name . ' must hold 1 or ' . self::MAX_SLOTS . ' comma-separated slots, each at least '
                    . self::MIN_SLOT_LENGTH . ' visible ASCII characters with no spaces';

                return [];
            }
        }

        return $slots;
    }
}
