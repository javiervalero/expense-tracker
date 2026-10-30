<?php

declare(strict_types=1);

namespace App\Receipts;

use RuntimeException;

/**
 * The worker's conditional update did not apply and the record is not "this upload, already settled": it is missing,
 * belongs to another upload, or is a pending record that expired (C3, C10 step 7). The message carries no id.
 */
final class ReceiptNotPending extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no pending receipt for this upload.');
    }
}
