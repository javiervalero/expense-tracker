<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Id;

use App\Infrastructure\Id\RandomUuidGenerator;
use App\Infrastructure\Id\Uuid;
use PHPUnit\Framework\TestCase;

final class RandomUuidGeneratorTest extends TestCase
{
    public function testGeneratesLowerCaseCanonicalVersion4Uuids(): void
    {
        $uuid = (new RandomUuidGenerator())->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        self::assertTrue(Uuid::isValid($uuid));
    }

    public function testGeneratesUniqueValues(): void
    {
        $generator = new RandomUuidGenerator();
        $seen = [];
        for ($i = 0; $i < 1000; ++$i) {
            $seen[$generator->generate()] = true;
        }

        self::assertCount(1000, $seen);
    }

    public function testVersionAndVariantBitsHoldForManyValues(): void
    {
        $generator = new RandomUuidGenerator();
        for ($i = 0; $i < 200; ++$i) {
            $uuid = $generator->generate();
            self::assertSame('4', $uuid[14]);
            self::assertContains($uuid[19], ['8', '9', 'a', 'b']);
        }
    }
}
