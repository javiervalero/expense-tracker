<?php

declare(strict_types=1);

namespace App\Receipts\Http;

use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;

/**
 * The texts of the field errors of the receipt endpoints, in one place. Built from the constants of the rules; a text
 * never repeats a value.
 */
final class ReceiptFieldMessages
{
    public static function contentType(): string
    {
        return 'Must be one of ' . implode(', ', array_map(static fn(ReceiptContentType $type): string => $type->value, ReceiptContentType::cases())) . '.';
    }

    public static function size(): string
    {
        return 'Must be a whole number of bytes from 1 to ' . ReceiptLimits::MAX_BYTES . '.';
    }
}
