<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use App\Infrastructure\Config\InvalidConfiguration;

/**
 * Where the data lives: the table (required), plus an endpoint (only for the local stand-in) and a region (on Lambda
 * the runtime sets it). Only variable names are reported on errors, never values.
 */
final readonly class DynamoDbSettings
{
    private const TABLE = 'DYNAMODB_TABLE';
    private const ENDPOINT = 'DYNAMODB_ENDPOINT';
    private const REGION = 'AWS_REGION';
    /** DynamoDB's own rule for table names: 3 to 255 of letters, digits, underscore, hyphen and dot. */
    private const TABLE_NAME_PATTERN = '/\A[A-Za-z0-9_.-]{3,255}\z/';

    private function __construct(public string $tableName, public ?string $endpoint, public ?string $region) {}

    /**
     * @param array<array-key, mixed> $env
     *
     * @throws InvalidConfiguration
     */
    public static function fromEnvironment(array $env): self
    {
        $errors = [];
        $table = self::table($env, $errors);
        $endpoint = self::optionalString($env, self::ENDPOINT, $errors);
        $region = self::optionalString($env, self::REGION, $errors);
        sort($errors);
        if ($errors !== []) {
            throw new InvalidConfiguration($errors);
        }

        return new self($table, $endpoint, $region);
    }

    /**
     * @param array<array-key, mixed> $env
     * @param list<string> $errors
     * @param-out list<string> $errors
     */
    private static function table(array $env, array &$errors): string
    {
        if (!array_key_exists(self::TABLE, $env)) {
            $errors[] = self::TABLE . ' is missing';

            return '';
        }
        $value = $env[self::TABLE];
        if (!is_string($value)) {
            $errors[] = self::TABLE . ' is not a string';

            return '';
        }
        if (trim($value) === '') {
            $errors[] = self::TABLE . ' is empty';

            return '';
        }
        if (preg_match(self::TABLE_NAME_PATTERN, $value) !== 1) {
            $errors[] = self::TABLE . ' is not a valid table name';

            return '';
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $env
     * @param list<string> $errors
     * @param-out list<string> $errors
     */
    private static function optionalString(array $env, string $name, array &$errors): ?string
    {
        $value = $env[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $errors[] = $name . ' is not a string';

            return null;
        }

        return $value;
    }
}
