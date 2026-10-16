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

        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid);
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

    public function testFromBytesFormatsAFixedVector(): void
    {
        self::assertSame('ffffffff-ffff-4fff-bfff-ffffffffffff', RandomUuidGenerator::fromBytes(str_repeat("\xFF", 16)));
        self::assertSame('00000000-0000-4000-8000-000000000000', RandomUuidGenerator::fromBytes(str_repeat("\x00", 16)));
        self::assertSame('00010203-0405-4607-8809-0a0b0c0d0e0f', RandomUuidGenerator::fromBytes(implode('', array_map(chr(...), range(0, 15)))));
    }

    public function testFromBytesRejectsAWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RandomUuidGenerator::fromBytes(str_repeat("\x00", 15));
    }
}
