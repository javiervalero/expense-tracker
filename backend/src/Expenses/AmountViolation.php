<?php

declare(strict_types=1);

namespace App\Expenses;

/** Why an amount was refused (B1): the stable code is the reason of the field error. */
enum AmountViolation: string
{
    case NotAnInteger = 'not_an_integer';
    case TooSmall = 'too_small';
    case TooLarge = 'too_large';
}
