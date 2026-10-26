<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\PartitionCall\Clean;

use App\Auth\AuthKeys;

/**
 * Prose may say AuthKeys::userPartition($id) without calling it.
 */
final class Clean
{
    public function run(object $owner): string
    {
        $text = 'AuthKeys::userPartition($id) in a string is fine';
        // AuthKeys::userPartition($id) in a comment is fine too.

        return AuthKeys::userPartitionOf($owner) . $text . $owner->userPartition;
    }
}
