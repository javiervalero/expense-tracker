<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\TemporaryTable;
use Aws\Command;
use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;

final class TemporaryTableTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    private function client(MockHandler $mock): DynamoDbClient
    {
        $this->sent = [];
        $client = new DynamoDbClient([
            'version' => '2012-08-10',
            'region' => 'eu-west-1',
            'credentials' => ['key' => 'localdev', 'secret' => 'localdevsecret'],
            'handler' => $mock,
            'retries' => 0,
        ]);
        $client->getHandlerList()->appendInit(function (callable $next): callable {
            return function (CommandInterface $command) use ($next): mixed {
                $this->sent[] = $command->getName();

                return $next($command);
            };
        }, 'record');

        return $client;
    }

    public function testTheTableIsDeletedAndTheErrorRethrownWhenWaitingForItFails(): void
    {
        $mock = new MockHandler([
            new Result([]),
            new DynamoDbException('describe failed', new Command('DescribeTable'), ['code' => 'AccessDeniedException']),
            new Result([]),
        ]);

        try {
            TemporaryTable::create($this->client($mock));
            self::fail('Expected the waiter failure');
        } catch (\Throwable $e) {
            self::assertStringContainsString('describe failed', $e->getMessage());
        }

        self::assertSame(['CreateTable', 'DescribeTable', 'DeleteTable'], $this->sent);
    }

    public function testTheOriginalErrorSurvivesWhenTheCleanupFailsToo(): void
    {
        $mock = new MockHandler([
            new Result([]),
            new DynamoDbException('describe failed', new Command('DescribeTable'), ['code' => 'AccessDeniedException']),
            new DynamoDbException('delete failed', new Command('DeleteTable'), ['code' => 'InternalServerError']),
        ]);

        try {
            TemporaryTable::create($this->client($mock));
            self::fail('Expected the waiter failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('describe failed', $e->getMessage());
            self::assertStringNotContainsString('delete failed', $e->getMessage());
        }
    }
}
