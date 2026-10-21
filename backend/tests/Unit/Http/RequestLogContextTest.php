<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\RequestLogContext;
use PHPUnit\Framework\TestCase;

final class RequestLogContextTest extends TestCase
{
    public function testTheClientIpIsNullUntilRecorded(): void
    {
        self::assertNull((new RequestLogContext())->clientIp());
    }

    public function testTheRecordedClientIpIsReturned(): void
    {
        $context = new RequestLogContext();

        $context->recordClientIp('203.0.113.7');

        self::assertSame('203.0.113.7', $context->clientIp());
    }

    public function testASecondWriteIsIgnoredSoTheFirstAddressStands(): void
    {
        $context = new RequestLogContext();
        $context->recordClientIp('203.0.113.7');

        $context->recordClientIp('198.51.100.9');

        self::assertSame('203.0.113.7', $context->clientIp());
    }

    public function testAnUnknownAddressRecordedFirstIsNotOverwrittenEither(): void
    {
        $context = new RequestLogContext();
        $context->recordClientIp(null);

        $context->recordClientIp('198.51.100.9');

        self::assertNull($context->clientIp());
    }

    public function testTheUserIdIsNullUntilRecordedAndTheFirstWriteStands(): void
    {
        $context = new RequestLogContext();
        self::assertNull($context->userId());

        $context->recordUserId('3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b');
        $context->recordUserId('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');

        self::assertSame('3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b', $context->userId());
    }
}
