<?php

declare(strict_types=1);

namespace App\Receipts;

use RuntimeException;

/**
 * The worker was told about an upload (C10 step 3) whose record is still the pending one but whose file is not in the
 * bucket. Throwing it makes the queue deliver the message again, then dead-letter it. The message carries no key.
 */
final class ReceiptObjectMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The uploaded receipt is not in the bucket although its record is pending.');
    }
}
