<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\Api\OfflineServices;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;

final class OfflineServicesTest extends TestCase
{
    public function testAStoreCallThroughTheOfflineClientCompletesWithoutLookingUpCredentials(): void
    {
        $mock = new MockHandler([new Result(['Item' => ['PK' => ['S' => 'A'], 'SK' => ['S' => 'B']]])]);
        $services = OfflineServices::create($mock);

        $item = $services->items->getItem(['PK' => 'A', 'SK' => 'B']);

        self::assertSame(['PK' => 'A', 'SK' => 'B'], $item);
    }
}
