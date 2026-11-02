<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ReceiptOwnerPartition\Clean;

use App\Auth\AuthKeys;

/**
 * Prose may say AuthKeys::receiptOwnerPartition($id) without calling it.
 */
final class Clean
{
    public function run(object $owner): string
    {
        $text = 'AuthKeys::receiptOwnerPartition($id) in a string is fine';
        // AuthKeys::receiptOwnerPartition($id) in a comment is fine too.

        return AuthKeys::userPartitionOf($owner) . $text . $owner->receiptOwnerPartition;
    }
}
