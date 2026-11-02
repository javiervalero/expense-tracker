<?php

declare(strict_types=1);

namespace App\Receipts;

/** Why the worker refused a receipt (C2, C11). The values are what the record stores and the API shows. */
enum FailureReason: string
{
    case UnsupportedType = 'unsupported-type';
    case TooLarge = 'too-large';
    case Unreadable = 'unreadable';
}
