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
}
