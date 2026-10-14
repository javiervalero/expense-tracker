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

    /**
     * One slot: visible ASCII only (no space, no control character), without `"` and `\` (which JSON escapes, and
     * would make the secret look different in an encoded log line), long enough to resist guessing.
     */
    private const SLOT_PATTERN = '/\A[\x21\x23-\x5B\x5D-\x7E]{' . self::MIN_SLOT_LENGTH . ',}\z/';

    /**
     * @param list<string> $originVerifySecrets the accepted `X-Origin-Verify` values (slot A, then B for a rotation)
     */
    private function __construct(public array $originVerifySecrets) {}

    /**
     * @param array<array-key, mixed> $env
     *
     * @throws InvalidConfiguration
     */
    public static function fromEnvironment(array $env): self
    {
        $errors = [];
        $secrets = self::originVerifySecrets($env, $errors);
        sort($errors);
        if ($errors !== []) {
            throw new InvalidConfiguration($errors);
        }

        return new self($secrets);
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
