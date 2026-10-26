<?php

declare(strict_types=1);

namespace App\Categories;

use RuntimeException;

/** The category does not exist (any more) for this user. The message carries no id. */
final class CategoryNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The category does not exist.');
    }
}
