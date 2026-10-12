<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Support;

use LocalAws\Support\LocalEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalEndpointTest extends TestCase
{
    #[DataProvider('allowedEndpoints')]
    public function testAcceptsLocalStandIns(string $endpoint): void
    {
        LocalEndpoint::assertAllowed('SOME_ENDPOINT', $endpoint);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedEndpoints(): iterable
    {
        yield 'compose service' => ['http://s3-events-relay:8080/'];
        yield 'compose service with digit' => ['http://sqs2:9324'];
        yield 'localhost' => ['http://localhost:9000'];
        yield 'loopback v4' => ['http://127.0.0.1:9324'];
        yield 'loopback v6' => ['http://[::1]:8000'];
        yield 'docker host alias' => ['http://host.docker.internal:8000'];
    }

    #[DataProvider('refusedEndpoints')]
    public function testRefusesEverythingElse(string $endpoint): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOME_ENDPOINT');

        LocalEndpoint::assertAllowed('SOME_ENDPOINT', $endpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedEndpoints(): iterable
    {
        yield 'decimal IPv4 literal' => ['http://2130706433:8000'];
        yield 'hex IPv4 literal' => ['http://0x7f000001:8000'];
        yield 'all-digit single label' => ['http://874000000:8000'];
        yield 'short dotted IPv4 form' => ['http://127.1:8000'];
        yield 'label starting with a digit' => ['http://1abc:8000'];
        yield 'dotted non-AWS host' => ['http://example.com:8000'];
        yield 'real AWS' => ['https://sqs.eu-west-1.amazonaws.com'];
        yield 'userinfo' => ['http://user@localhost:8000'];
        yield 'underscore' => ['http://my_host:8000'];
    }
}
