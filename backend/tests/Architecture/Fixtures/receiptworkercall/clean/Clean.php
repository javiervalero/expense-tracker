<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ReceiptWorkerCall\Clean;

/**
 * Prose may say $receipts->findByKey($key), markReady() or markFailed() without calling them.
 */
final class Clean
{
    public function findByKey(string $key): string
    {
        $text = '$receipts->markReady($key) in a string is fine';
        // $receipts->markFailed($key) in a comment is fine too.

        return $key . $text . $this->markReady;
    }
}
