<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\SessionTokenGenerator\Clean;

use App\Auth\Session\SessionRepository;

/**
 * Prose may say new SessionRepository($items, $clock, $generator) without constructing one.
 */
final class Clean
{
    public function run(object $items, object $clock): array
    {
        $text = 'new SessionRepository($items, $clock, $generator) in a string is fine';
        // new SessionRepository($items, $clock, $generator) in a comment is fine too.

        return [
            new SessionRepository($items, $clock),
            new \App\Auth\Session\SessionRepository(
                $items,
                $clock,
            ),
            new SessionRepository(self::pair($items, $clock, $text), [1, 2, 3]),
        ];
    }

    private static function pair(object $a, object $b, string $c): object
    {
        return new \stdClass();
    }
}
