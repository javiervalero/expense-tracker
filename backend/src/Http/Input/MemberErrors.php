<?php

declare(strict_types=1);

namespace App\Http\Input;

use App\Http\Problem\FieldError;

/**
 * Turns the result of a member check (a value, or a {@see FieldError}) into the list a body collects its errors in.
 */
final class MemberErrors
{
    private function __construct() {}

    /**
     * @return list<FieldError>
     */
    public static function of(mixed $member): array
    {
        return $member instanceof FieldError ? [$member] : [];
    }
}
