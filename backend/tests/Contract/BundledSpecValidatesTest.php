<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Tests\Support\Contract\ContractValidator;
use App\Tests\Support\Contract\OpenApiDocument;
use PHPUnit\Framework\TestCase;

/**
 * Runs the validator against the real bundle (contract/dist/openapi.json), so it needs `make contract-bundle` first.
 * Locally a missing bundle skips; in CI (`CI` set) it fails, so the suite can never pass by silently skipping.
 */
final class BundledSpecValidatesTest extends TestCase
{
    private static function validator(): ContractValidator
    {
        $file = __DIR__ . '/../../../contract/dist/openapi.json';
        if (!is_file($file)) {
            $message = 'contract/dist/openapi.json is missing; run `make contract-bundle`.';
            if ((string) getenv('CI') !== '') {
                self::fail($message);
            }
            self::markTestSkipped($message);
        }

        return new ContractValidator(OpenApiDocument::fromFile($file));
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
