<?php

declare(strict_types=1);

namespace App\Categories;

use RuntimeException;

/** Another category of the same user holds the name lock (B6). The message carries no name. */
final class CategoryNameTaken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The category name is already in use.');
    }
}
