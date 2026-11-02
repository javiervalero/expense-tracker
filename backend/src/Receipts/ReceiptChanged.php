<?php

declare(strict_types=1);

namespace App\Receipts;

use RuntimeException;

/** The expense's receipt record changed between the read and the write of an upload start (C4: answer 409). The message carries no id. */
final class ReceiptChanged extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The receipt changed while the upload was being started.');
    }
}
