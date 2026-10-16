<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Id;

use App\Infrastructure\Id\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testAcceptsALowerCaseCanonicalVersion4Uuid(): void
    {
        self::assertTrue(Uuid::isValid('3f2b8c1e-5a7d-4e90-9b1a-0c2d4e6f8a1b'));
        self::assertTrue(Uuid::isValid('3f2b8c1e-5a7d-4e90-8b1a-0c2d4e6f8a1b'));
        self::assertTrue(Uuid::isValid('3f2b8c1e-5a7d-4e90-ab1a-0c2d4e6f8a1b'));
    }

    #[DataProvider('invalidValues')]
    public function testRefuses(string $value): void
    {
        self::assertFalse(Uuid::isValid($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'upper case' => ['3F2B8C1E-5A7D-4E90-9B1A-0C2D4E6F8A1B'];
        yield 'mixed case' => ['3f2b8c1e-5a7d-4E90-9b1a-0c2d4e6f8a1b'];
        yield 'braces' => ['{3f2b8c1e-5a7d-4e90-9b1a-0c2d4e6f8a1b}'];
        yield 'version 1' => ['3f2b8c1e-5a7d-1e90-9b1a-0c2d4e6f8a1b'];
        yield 'version 5' => ['3f2b8c1e-5a7d-5e90-9b1a-0c2d4e6f8a1b'];
        yield 'bad variant' => ['3f2b8c1e-5a7d-4e90-cb1a-0c2d4e6f8a1b'];
        yield 'nil uuid' => ['00000000-0000-0000-0000-000000000000'];
        yield 'leading space' => [' 3f2b8c1e-5a7d-4e90-9b1a-0c2d4e6f8a1b'];
        yield 'trailing space' => ['3f2b8c1e-5a7d-4e90-9b1a-0c2d4e6f8a1b '];
        yield 'trailing newline' => ["3f2b8c1e-5a7d-4e90-9b1a-0c2d4e6f8a1b\n"];
        yield 'no hyphens' => ['3f2b8c1e5a7d4e909b1a0c2d4e6f8a1b'];
        yield 'empty' => [''];
        yield 'not hex' => ['3f2b8c1e-5a7d-4e90-9b1a-0c2d4e6f8a1g'];
        yield 'too long' => ['3f2b8c1e-5a7d-4e90-9b1a-0c2d4e6f8a1b0'];
    }
}
