<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Every operation documents the 403 (origin check, WP6) and 500 problems the middleware can answer for it. The
 * validator fails a real response whose status the contract does not list, so this keeps that list complete.
 */
final class EveryOperationDocumentsErrorsTest extends TestCase
{
    private const array REQUIRED_STATUSES = [403, 500];

    public function testEveryOperationDocumentsTheErrorStatuses(): void
    {
        $document = BundledSpec::document();
        $operations = $document->operations();
        self::assertNotSame([], $operations, 'The contract has no operations.');
        $missing = [];
        foreach ($operations as [$method, $template]) {
            foreach (self::REQUIRED_STATUSES as $status) {
                if ($document->response($method, $template, $status) === null) {
                    $missing[] = "$method $template $status";
                }
            }
        }

        self::assertSame([], $missing, 'Undocumented responses: ' . implode(', ', $missing));
    }
}
