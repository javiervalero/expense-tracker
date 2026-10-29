<?php

declare(strict_types=1);

namespace App\Receipts;

/** Where a receipt is in its life (C2): waiting for the worker, accepted, or refused. */
enum ReceiptStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
