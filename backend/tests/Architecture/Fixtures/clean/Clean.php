<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Clean;

use App\Bref\Thing;

/**
 * Fixture that must produce zero hits. It mentions the word Bref and AWS_LAMBDA_FUNCTION_NAME in prose,
 * and refers to App\Bref\Thing, which is our own namespace and not the runtime library.
 *
 * @param Thing $thing
 */
final class Clean
{
    // Plain comments may talk about Bref\Context or AWS_LAMBDA_FUNCTION_NAME without leaking anything.
    public const BREFFER = 'not a Bref variable';

    public function read(Thing $thing): string
    {
        $brefs = ['Brefs', 'MY_AWS_LAMBDA_NOT', 'prefix_BREFFER'];
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        return getenv('APP_ENV') . $method . $brefs[0] . $thing::class . \App\Bref\Other::class;
    }
}
