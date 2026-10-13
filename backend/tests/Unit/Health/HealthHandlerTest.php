<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Health\HealthHandler;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class HealthHandlerTest extends TestCase
{
    public function testItAnswersOkAsJson(): void
    {
        $response = (new HealthHandler())->handle(new Request('GET', '/api/v1/health', [], [], ''));

        self::assertSame(200, $response->status);
        self::assertSame(['application/json'], $response->headerValues('Content-Type'));
        self::assertSame('{"status":"ok"}', $response->body);
    }
}
