<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Aws\DynamoDb\DynamoDbClient;

/**
 * A DynamoDB table with a random name, created from infra/dynamodb/table.json (the same file the bootstrap and
 * Terraform read), for one test. {@see self::drop()} deletes only the table this object created, never the app table.
 */
final class TemporaryTable
{
    private const SCHEMA_FILE = __DIR__ . '/../../../infra/dynamodb/table.json';
    private const NAME_PREFIX = 'it-temp-';

    private bool $dropped = false;

    private function __construct(private readonly DynamoDbClient $client, public readonly string $name) {}

    public static function create(DynamoDbClient $client): self
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA_FILE), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($schema)) {
            throw new \RuntimeException('infra/dynamodb/table.json is not a JSON object.');
        }
        // TTL is not part of CreateTable; it is enabled by a separate call that these tests do not need.
        unset($schema['TimeToLiveAttribute']);
        $name = self::NAME_PREFIX . bin2hex(random_bytes(8));

        $client->execute($client->getCommand('CreateTable', ['TableName' => $name] + $schema));
        $client->waitUntil('TableExists', ['TableName' => $name]);

        return new self($client, $name);
    }

    public function drop(): void
    {
        if ($this->dropped) {
            return;
        }
        $this->dropped = true;
        $this->client->deleteTable(['TableName' => $this->name]);
    }
}
