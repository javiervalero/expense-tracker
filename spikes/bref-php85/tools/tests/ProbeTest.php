<?php

declare(strict_types=1);

namespace Spike\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Spike\Probe;

#[CoversClass(Probe::class)]
final class ProbeTest extends TestCase
{
    public function testAddsNumbers(): void
    {
        self::assertSame(5, (new Probe())->add(2, 3));
    }

    public function testJsonSchema202012AcceptsValidAndRejectsInvalid(): void
    {
        $probe = new Probe();
        self::assertTrue($probe->schemaIsValid(['amount' => 5]));
        self::assertFalse($probe->schemaIsValid(['amount' => 0]));
    }

    public function testAwsClientsAreCreated(): void
    {
        self::assertSame(
            ['Aws\DynamoDb\DynamoDbClient', 'Aws\S3\S3Client', 'Aws\Sqs\SqsClient'],
            (new Probe())->clientClasses(),
        );
    }
}
