<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Tests\Integration\Isolation\CrossUserIsolationApiTest;
use PHPUnit\Framework\TestCase;

/**
 * A secured operation that the cross-user isolation suite does not cover fails CI: the secured operations of the
 * bundled contract (those with a non-empty `security`), as `operationId => [METHOD, path template]`, must equal
 * {@see CrossUserIsolationApiTest::SECURED_CALLS}, which the suite iterates. A mislabelled row (right id, wrong method or
 * path) fails too. Phase 5's receipt routes are caught here until the suite covers them.
 */
final class CrossUserCoverageTest extends TestCase
{
    public function testTheSecuredOperationsAreExactlyTheOnesTheIsolationSuiteCovers(): void
    {
        $secured = self::securedOperations();
        $covered = CrossUserIsolationApiTest::SECURED_CALLS;
        ksort($covered);

        self::assertNotSame([], $secured, 'The contract has no secured operation.');
        self::assertSame(
            [],
            array_values(array_diff(array_keys($secured), array_keys($covered))),
            'Secured operations without cross-user coverage (add them to CrossUserIsolationApiTest): ' . implode(', ', array_diff(array_keys($secured), array_keys($covered))),
        );
        self::assertSame(
            [],
            array_values(array_diff(array_keys($covered), array_keys($secured))),
            'Covered operations that are not secured operations of the contract: ' . implode(', ', array_diff(array_keys($covered), array_keys($secured))),
        );
        self::assertSame($secured, $covered, 'A covered operation has another method or path template than in the contract.');
    }

    /** @return array<string, array{0: string, 1: string}> operationId => [METHOD, path template], sorted by operationId */
    private static function securedOperations(): array
    {
        $document = BundledSpec::document();
        $spec = json_decode($document->json(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        $paths = $spec['paths'] ?? null;
        self::assertIsArray($paths);
        $operations = [];
        foreach ($document->operations() as [$method, $template]) {
            if (($document->security($method, $template) ?? []) === []) {
                continue;
            }
            $item = $paths[$template] ?? null;
            $operation = is_array($item) ? ($item[strtolower($method)] ?? null) : null;
            $id = is_array($operation) ? ($operation['operationId'] ?? null) : null;
            self::assertIsString($id, "$method $template has no operationId");
            $operations[$id] = [$method, $template];
        }
        ksort($operations);

        return $operations;
    }
}
