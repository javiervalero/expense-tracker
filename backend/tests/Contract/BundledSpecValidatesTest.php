<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Tests\Support\Contract\ContractValidator;
use PHPUnit\Framework\TestCase;

/**
 * Runs the validator against the real bundle (contract/dist/openapi.json), so it needs `make contract-bundle` first
 * (see {@see BundledSpec}: a missing bundle skips locally and fails in CI).
 */
final class BundledSpecValidatesTest extends TestCase
{
    private static function validator(): ContractValidator
    {
        return new ContractValidator(BundledSpec::document());
    }

    public function testLoadsTheRealBundledSpec(): void
    {
        $validator = self::validator();
        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

        self::assertSame([], $validator->validate('GET', '/health', 200, $headers, '{"status":"ok"}'));
        self::assertNotSame([], $validator->validate('GET', '/health', 200, $headers, '{"status":"down"}'));
        self::assertNotSame([], $validator->validate('GET', '/health', 200, $headers, '{"status":"ok","x":1}'));
    }
}
