<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Receipts\ReceiptRepository;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 (C10): ReceiptWorkerCallTest and ReceiptOwnerPartitionCallTest guard the key-only methods by name. A new public
 * method on ReceiptRepository (for example a wrapper that takes only an object key) would bypass them. So the public
 * surface is pinned: adding a public method means updating this list and having the change reviewed.
 */
final class ReceiptRepositoryPublicSurfaceTest extends TestCase
{
    private const PUBLIC_METHODS = ['__construct', 'find', 'findByKey', 'startUpload', 'remove', 'markReady', 'markFailed', 'markReplaced'];

    public function testThePublicMethodsAreExactlyTheReviewedOnes(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(ReceiptRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);
        $expected = self::PUBLIC_METHODS;
        sort($expected);

        self::assertSame($expected, $methods);
    }
}
