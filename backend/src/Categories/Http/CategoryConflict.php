<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Categories\CategoryLimits;
use App\Http\Problem\HttpProblem;

/**
 * The three `409` answers of the category endpoints (the contract's `EditConflict`). The detail says which one it is
 * and never repeats a name, an id or a version.
 */
final class CategoryConflict
{
    private const STATUS = 409;

    private function __construct() {}

    public static function nameTaken(): HttpProblem
    {
        return HttpProblem::of(self::STATUS, 'A category with this name already exists.');
    }

    public static function staleVersion(): HttpProblem
    {
        return HttpProblem::of(self::STATUS, 'The category changed since you loaded it. Reload it and try again.');
    }

    public static function limitReached(): HttpProblem
    {
        return HttpProblem::of(self::STATUS, 'You already have the maximum of ' . CategoryLimits::MAX_PER_USER . ' categories. Delete one first.');
    }
}
