<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

/**
 * One write inside {@see ItemStore::transactWrite()}. Values are plain PHP values (the store marshals them) and are
 * only ever passed as expression values, never spliced into an expression. Every expression string and attribute-name
 * map is typed `literal-string`, so PHPStan rejects one built from input.
 */
final readonly class TransactionOperation
{
    public const string PUT = 'Put';
    public const string UPDATE = 'Update';
    public const string DELETE = 'Delete';
    public const string CONDITION_CHECK = 'ConditionCheck';

    /**
     * @param array<string, mixed> $keyOrItem the full item for a put, the key otherwise
     * @param literal-string|null $updateExpression
     * @param literal-string|null $condition
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     */
    private function __construct(
        public string $kind,
        public array $keyOrItem,
        public ?string $updateExpression,
        public ?string $condition,
        public array $names,
        public array $values,
    ) {}

    /**
     * @param array<string, mixed> $item
     * @param literal-string|null $condition
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     */
    public static function put(array $item, ?string $condition = null, array $names = [], array $values = []): self
    {
        return new self(self::PUT, $item, null, $condition, $names, $values);
    }

    /**
     * @param array<string, mixed> $key
     * @param literal-string $updateExpression
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     * @param literal-string|null $condition
     */
    public static function update(array $key, string $updateExpression, array $names, array $values, ?string $condition = null): self
    {
        return new self(self::UPDATE, $key, $updateExpression, $condition, $names, $values);
    }

    /**
     * @param array<string, mixed> $key
     * @param literal-string|null $condition
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     */
    public static function delete(array $key, ?string $condition = null, array $names = [], array $values = []): self
    {
        return new self(self::DELETE, $key, null, $condition, $names, $values);
    }

    /**
     * @param array<string, mixed> $key
     * @param literal-string $condition
     * @param array<string, literal-string> $names
     * @param array<string, mixed> $values
     */
    public static function conditionCheck(array $key, string $condition, array $names = [], array $values = []): self
    {
        return new self(self::CONDITION_CHECK, $key, null, $condition, $names, $values);
    }
}
