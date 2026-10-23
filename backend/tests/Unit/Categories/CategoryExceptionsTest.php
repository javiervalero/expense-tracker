<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories;

use App\Categories\CategoryNameTaken;
use App\Categories\CategoryNotFound;
use App\Infrastructure\DynamoDb\StaleVersion;
use PHPUnit\Framework\TestCase;

final class CategoryExceptionsTest extends TestCase
{
    public function testTheMessagesCarryNoNamesIdsOrItemData(): void
    {
        self::assertSame('The category name is already in use.', (new CategoryNameTaken())->getMessage());
        self::assertSame('The category does not exist.', (new CategoryNotFound())->getMessage());
        self::assertSame('The item changed since it was read.', (new StaleVersion())->getMessage());
    }
}
