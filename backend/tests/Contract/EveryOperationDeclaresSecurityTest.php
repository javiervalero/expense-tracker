<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Fail closed: the contract has no global `security` default, so an operation that forgets its `security` key would
 * be read as public by nobody and as protected by nobody. Every operation must say which one it is (`security: []`
 * for public).
 */
final class EveryOperationDeclaresSecurityTest extends TestCase
{
    public function testEveryOperationSaysExplicitlyWhetherItIsProtected(): void
    {
        $document = BundledSpec::document();
        self::assertNotSame([], $document->operations(), 'The contract has no operations.');
        $silent = [];
        foreach ($document->operations() as [$method, $template]) {
            if ($document->security($method, $template) === null) {
                $silent[] = "$method $template";
            }
        }

        self::assertSame([], $silent, 'Operations without an explicit `security`: ' . implode(', ', $silent));
    }

    public function testTheContractHasNoGlobalSecurityDefault(): void
    {
        self::assertNull(BundledSpec::document()->globalSecurity(), 'A global `security` default hides operations that forget their own.');
    }
}
