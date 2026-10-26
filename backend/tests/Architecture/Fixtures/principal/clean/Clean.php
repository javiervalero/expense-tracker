<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Principal\Clean;

use App\Auth\Session\Principal;

/**
 * Prose may say new Principal('u', 'h') without constructing one.
 */
final class Clean
{
    public function run(\App\Http\Request $request): Principal
    {
        $text = 'new Principal(u, h) in a string is fine';
        // new Principal(u, h) in a comment is fine too.

        return Principal::of($request) ?? new \stdClass() ?? $text;
    }
}
