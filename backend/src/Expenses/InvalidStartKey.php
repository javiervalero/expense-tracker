<?php

declare(strict_types=1);

namespace App\Expenses;

use InvalidArgumentException;

/**
 * A start key that is not an expense position inside the requested range (B18): wrong shape, a sort key that is not
 * `EXP#<uuid>`, an index key that disagrees with it, or a date outside the range. A caller maps it to the generic
 * cursor error (400); every other InvalidArgumentException of the repository is a caller bug. The message carries
 * no key.
 */
final class InvalidStartKey extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('The start key is not an expense position in the range.');
    }
}
