<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

use App\Infrastructure\Config\InvalidConfiguration;
use App\Infrastructure\Config\LocalEndpoint;

/**
 * Where the receipts live: two buckets (required), and for the local stand-in two endpoints that go together. Only
 * variable names are reported on errors, never values.
 *
 * `S3_ENDPOINT` is where the API CALLS S3 (locally the nginx listener of decision 15, `http://api:9100`);
 * `S3_PUBLIC_ENDPOINT` is the address the browser uses and the one every signature is made for (locally
 * `http://localhost:9000`). Neither is set on AWS: the SDK then builds the virtual-hosted URLs itself.
 */
final readonly class S3Settings
{
    private const RECEIPTS_BUCKET = 'RECEIPTS_BUCKET';
    private const THUMBNAILS_BUCKET = 'THUMBNAILS_BUCKET';
    private const ENDPOINT = 'S3_ENDPOINT';
    private const PUBLIC_ENDPOINT = 'S3_PUBLIC_ENDPOINT';
    private const REGION = 'AWS_REGION';
    private const REGION_PATTERN = '/\A[a-z]{2}(-[a-z]+)+-\d\z/';
    /**
     * S3's rule (3 to 63 of lower-case letters, digits and hyphens, starting and ending with a letter or digit), without
     * dots: a dot in a virtual-hosted bucket name breaks the wildcard TLS certificate.
     */
    private const BUCKET_NAME_PATTERN = '/\A[a-z0-9][a-z0-9-]{1,61}[a-z0-9]\z/';

    private function __construct(
        public string $receiptsBucket,
        public string $thumbnailsBucket,
        public ?string $endpoint,
        public ?string $publicEndpoint,
        public ?string $region,
    ) {}

    /**
     * @param array<array-key, mixed> $env
     *
     * @throws InvalidConfiguration
     */
    public static function fromEnvironment(array $env): self
    {
        $errors = [];
        $receipts = self::bucket($env, self::RECEIPTS_BUCKET, $errors);
        $thumbnails = self::bucket($env, self::THUMBNAILS_BUCKET, $errors);
        if ($receipts !== '' && $receipts === $thumbnails) {
            $errors[] = self::THUMBNAILS_BUCKET . ' must differ from ' . self::RECEIPTS_BUCKET;
        }
        $localOnly = static fn(string $value): ?string => LocalEndpoint::violation($value);
        $endpoint = self::optionalString($env, self::ENDPOINT, $errors, $localOnly);
        $publicEndpoint = self::optionalString($env, self::PUBLIC_ENDPOINT, $errors, $localOnly);
        $region = self::optionalString(
            $env,
            self::REGION,
            $errors,
            static fn(string $value): ?string => preg_match(self::REGION_PATTERN, $value) === 1 ? null : 'is not a valid region',
        );
        if ($endpoint !== null && $publicEndpoint === null && !self::isPresent($env, self::PUBLIC_ENDPOINT)) {
            $errors[] = self::PUBLIC_ENDPOINT . ' is required when ' . self::ENDPOINT . ' is set';
        }
        if ($publicEndpoint !== null && $endpoint === null && !self::isPresent($env, self::ENDPOINT)) {
            $errors[] = self::ENDPOINT . ' is required when ' . self::PUBLIC_ENDPOINT . ' is set';
        }
        sort($errors);
        if ($errors !== []) {
            throw new InvalidConfiguration($errors);
        }

        return new self($receipts, $thumbnails, $endpoint, $publicEndpoint, $region);
    }

    /**
     * @param array<array-key, mixed> $env
     */
    private static function isPresent(array $env, string $name): bool
    {
        $value = $env[$name] ?? null;

        return $value !== null && $value !== '';
    }

    /**
     * @param array<array-key, mixed> $env
     * @param list<string> $errors
     * @param-out list<string> $errors
     */
    private static function bucket(array $env, string $name, array &$errors): string
    {
        if (!array_key_exists($name, $env)) {
            $errors[] = $name . ' is missing';

            return '';
        }
        $value = $env[$name];
        if (!is_string($value)) {
            $errors[] = $name . ' is not a string';

            return '';
        }
        if (trim($value) === '') {
            $errors[] = $name . ' is empty';

            return '';
        }
        if (preg_match(self::BUCKET_NAME_PATTERN, $value) !== 1) {
            $errors[] = $name . ' is not a valid bucket name';

            return '';
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $env
     * @param list<string> $errors
     * @param-out list<string> $errors
     * @param callable(string): ?string $violation the reason a present value is refused (never containing it), or null
     */
    private static function optionalString(array $env, string $name, array &$errors, callable $violation): ?string
    {
        $value = $env[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $errors[] = $name . ' is not a string';

            return null;
        }
        $reason = $violation($value);
        if ($reason !== null) {
            $errors[] = $name . ' ' . $reason;

            return null;
        }

        return $value;
    }
}
