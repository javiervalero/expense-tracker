<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Config;

use App\Infrastructure\Config\ApiConfig;
use App\Infrastructure\Config\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class ApiConfigTest extends TestCase
{
    public function testAnEnvironmentWithoutRequiredVariablesYetIsValid(): void
    {
        $this->expectNotToPerformAssertions();
        ApiConfig::fromEnvironment([]);
    }

    public function testAllProblemsAreCollectedAndOnlyVariableNamesAreReported(): void
    {
        $env = ['PRESENT' => 'top-secret-value', 'EMPTY' => '', 'NOT_A_STRING' => 12];

        try {
            ApiConfig::validate($env, ['PRESENT', 'EMPTY', 'MISSING', 'NOT_A_STRING']);
            self::fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            self::assertSame(
                ['EMPTY is empty', 'MISSING is missing', 'NOT_A_STRING is not a string'],
                $e->errors,
            );
            self::assertStringContainsString('EMPTY', $e->getMessage());
            self::assertStringContainsString('MISSING', $e->getMessage());
            self::assertStringNotContainsString('top-secret-value', $e->getMessage());
        }
    }

    public function testValidWhenEveryRequiredVariableIsPresent(): void
    {
        $this->expectNotToPerformAssertions();
        ApiConfig::validate(['A' => 'x'], ['A']);
    }
}
