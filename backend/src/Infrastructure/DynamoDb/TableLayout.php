<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

/**
 * The attribute and index names of the single table (ADR 0002, infra/dynamodb/table.json). Key values for each kind
 * of item are built by the features that own them.
 */
final class TableLayout
{
    public const string PK = 'PK';
    public const string SK = 'SK';
    public const string LSI1 = 'LSI1';
    public const string LSI1SK = 'LSI1SK';
    public const string EXPIRES_AT = 'expiresAt';
}
