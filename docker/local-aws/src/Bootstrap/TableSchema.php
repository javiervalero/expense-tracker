<?php

declare(strict_types=1);

namespace LocalAws\Bootstrap;

/**
 * The table definition of ADR 0002, read from infra/dynamodb/table.json (copied into the image as
 * config/dynamodb-table.json). The same file is the source of the AWS table in Phase 7, so local and AWS cannot drift.
 * It holds the CreateTable fields plus `TimeToLiveAttribute`; the table name is not in it (it is `DYNAMODB_TABLE`).
 */
final readonly class TableSchema
{
    private const REQUIRED_LISTS = ['AttributeDefinitions', 'KeySchema', 'LocalSecondaryIndexes'];
    private const BILLING_PROVISIONED = 'PROVISIONED';

    /**
     * @param array<string, mixed> $createFields what CreateTable takes, without the table name
     */
    private function __construct(private array $createFields, public string $timeToLiveAttribute) {}

    /**
     * @throws \RuntimeException when the file cannot be read, is not valid JSON or does not describe a table
     */
    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new \RuntimeException(sprintf('The table definition %s cannot be read.', $path));
        }

        return self::fromJson($json);
    }

    /**
     * @throws \RuntimeException when the text is not valid JSON or does not describe a table
     */
    public static function fromJson(string $json): self
    {
        try {
            $definition = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The table definition is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($definition) || array_is_list($definition)) {
            throw new \RuntimeException('The table definition must be a JSON object.');
        }
        /** @var array<string, mixed> $definition */
        if (array_key_exists('TableName', $definition)) {
            throw new \RuntimeException('The table definition must not contain TableName: the name comes from DYNAMODB_TABLE.');
        }
        foreach (self::REQUIRED_LISTS as $field) {
            if (!is_array($definition[$field] ?? null) || $definition[$field] === [] || !array_is_list($definition[$field])) {
                throw new \RuntimeException(sprintf('The table definition needs %s as a non-empty list.', $field));
            }
        }
        foreach (['BillingMode', 'TimeToLiveAttribute'] as $field) {
            if (!is_string($definition[$field] ?? null) || $definition[$field] === '') {
                throw new \RuntimeException(sprintf('The table definition needs %s as a non-empty string.', $field));
            }
        }
        $timeToLiveAttribute = $definition['TimeToLiveAttribute'];
        unset($definition['TimeToLiveAttribute']);

        return new self($definition, $timeToLiveAttribute);
    }

    /**
     * @return array<string, mixed>
     */
    public function createTableParameters(string $tableName): array
    {
        return ['TableName' => $tableName] + $this->createFields;
    }

    /**
     * Compares a DescribeTable `Table` with the schema on what the schema sets: attribute types, keys, the local
     * secondary indexes (local and global) with their projections, and the billing mode. Order of lists that DynamoDB may reorder and
     * the extra fields it adds (sizes, ARNs, status) do not count.
     *
     * @param array<array-key, mixed> $table
     *
     * @return list<string> the names of the fields that differ (empty when the table matches)
     */
    public function differencesFrom(array $table): array
    {
        $described = $this->comparable($table, $table['BillingModeSummary'] ?? null);
        $wanted = $this->comparable($this->createFields, ['BillingMode' => $this->createFields['BillingMode'] ?? null]);

        return array_keys(array_filter(
            $wanted,
            static fn(mixed $value, string $field): bool => $value !== $described[$field],
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @param array<array-key, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function comparable(array $source, mixed $billing): array
    {
        $billingMode = is_array($billing) && is_string($billing['BillingMode'] ?? null) ? $billing['BillingMode'] : self::BILLING_PROVISIONED;

        return [
            'AttributeDefinitions' => self::attributeTypes($source['AttributeDefinitions'] ?? null),
            'KeySchema' => self::keySchema($source['KeySchema'] ?? null),
            'LocalSecondaryIndexes' => self::indexes($source['LocalSecondaryIndexes'] ?? null),
            'GlobalSecondaryIndexes' => self::indexes($source['GlobalSecondaryIndexes'] ?? null),
            'BillingMode' => $billingMode,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function attributeTypes(mixed $definitions): array
    {
        $types = [];
        foreach (self::items($definitions) as $definition) {
            $name = $definition['AttributeName'] ?? null;
            $type = $definition['AttributeType'] ?? null;
            if (is_string($name) && is_string($type)) {
                $types[$name] = $type;
            }
        }
        ksort($types);

        return $types;
    }

    /**
     * @return list<array{string, string}> [attribute, key type] in key order (hash first)
     */
    private static function keySchema(mixed $keySchema): array
    {
        $pairs = [];
        foreach (self::items($keySchema) as $element) {
            $pairs[] = [is_string($element['AttributeName'] ?? null) ? $element['AttributeName'] : '', is_string($element['KeyType'] ?? null) ? $element['KeyType'] : ''];
        }

        return $pairs;
    }

    /**
     * @return array<string, array{list<array{string, string}>, string, list<string>}>
     */
    private static function indexes(mixed $indexes): array
    {
        $byName = [];
        foreach (self::items($indexes) as $index) {
            $name = $index['IndexName'] ?? null;
            $projection = is_array($index['Projection'] ?? null) ? $index['Projection'] : [];
            $extra = array_values(array_filter(is_array($projection['NonKeyAttributes'] ?? null) ? $projection['NonKeyAttributes'] : [], is_string(...)));
            sort($extra);
            if (is_string($name)) {
                $byName[$name] = [self::keySchema($index['KeySchema'] ?? null), is_string($projection['ProjectionType'] ?? null) ? $projection['ProjectionType'] : '', $extra];
            }
        }
        ksort($byName);

        return $byName;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }
}
