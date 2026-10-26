<?php

declare(strict_types=1);

namespace App\Categories;

/** The limits of the category feature (B8). */
final class CategoryLimits
{
    /** At most this many categories per user. It bounds the one query that every expense read makes (B16). */
    public const int MAX_PER_USER = 100;

    private function __construct() {}
}
