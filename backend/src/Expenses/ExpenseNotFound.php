<?php

declare(strict_types=1);

namespace App\Expenses;

use RuntimeException;

/** The expense does not exist (any more) for this user. The message carries no id. */
final class ExpenseNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The expense does not exist.');
    }
}
