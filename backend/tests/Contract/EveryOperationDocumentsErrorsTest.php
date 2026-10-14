<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Every operation documents the 500 problem the error middleware can answer for it (403 joins in WP6). The
 * validator fails a real response whose status the contract does not list, so this keeps that list complete.
 */
final class EveryOperationDocumentsErrorsTest extends TestCase
{
    private const array REQUIRED_STATUSES = [500];

    public function testEveryOperationDocumentsTheErrorStatuses(): void
    {
        $document = BundledSpec::document();
        $missing = [];
        foreach ($document->operations() as [$method, $template]) {
            foreach (self::REQUIRED_STATUSES as $status) {
                if ($document->response($method, $template, $status) === null) {
                    $missing[] = "$method $template $status";
                }
            }
        }

        self::assertNotSame([], $document->operations(), 'The contract has no operations.');
        self::assertSame([], $missing, 'Undocumented responses: ' . implode(', ', $missing));
    }
}
