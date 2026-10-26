<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use RuntimeException;

/**
 * An update carried a version that is no longer the stored one (B12): the item changed since the caller read it.
 * The message carries no item data.
 */
final class StaleVersion extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The item changed since it was read.');
    }
}
