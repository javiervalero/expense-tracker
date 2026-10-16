<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

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
     */
    private function __construct(public array $originVerifySecrets, public string $clientIpHeader) {}

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
        sort($errors);
        if ($errors !== []) {
            throw new InvalidConfiguration($errors);
        }

        return new self($secrets, $clientIpHeader);
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
