<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Http;

use App\Auth\Http\TooManyAttempts;
use PHPUnit\Framework\TestCase;

final class TooManyAttemptsTest extends TestCase
{
    public function testTheProblemIsOneGeneric429WithTheSecondsToWaitInRetryAfter(): void
    {
        $problem = TooManyAttempts::problem(897);

        self::assertSame(429, $problem->problem->status);
        self::assertSame('Too many attempts. Try again later.', $problem->problem->detail);
        self::assertSame(['Retry-After' => ['897']], $problem->headers);
    }
}
