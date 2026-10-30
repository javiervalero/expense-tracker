<?php

declare(strict_types=1);

namespace App\Receipts;

/** What {@see ReceiptRepository::markReady()} and `markFailed()` did when the record was still the pending one. */
enum SettleResult
{
    /** This call moved the record from pending to ready or failed. */
    case Applied;

    /** The same upload was already settled (a duplicate delivery): nothing was changed. */
    case AlreadyProcessed;
}
