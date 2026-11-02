<?php

declare(strict_types=1);

namespace App\Tests\Unit\Worker;

use App\Worker\WorkerRuntime;
use PHPUnit\Framework\TestCase;

final class WorkerRuntimeTest extends TestCase
{
    public function testTheMemoryLimitIsExplicitlySetToWhatFitsA1024MbLambda(): void
    {
        $before = ini_get('memory_limit');
        try {
            WorkerRuntime::configure();

            self::assertSame('768M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $before);
        }
    }

    public function testConfiguringTwiceChangesNothing(): void
    {
        $before = ini_get('memory_limit');
        try {
            WorkerRuntime::configure();
            WorkerRuntime::configure();

            self::assertSame('768M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $before);
        }
    }

    /** The limit is set where the Lambda handler file loads, before any record is processed (worker-only: the API is untouched). */
    public function testTheHandlerFileConfiguresTheRuntimeBeforeBuildingTheWorker(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/entrypoints/lambda/worker.php');

        $configure = strpos($source, 'WorkerRuntime::configure()');
        $create = strpos($source, 'WorkerFactory::create(');
        self::assertNotFalse($configure);
        self::assertNotFalse($create);
        self::assertLessThan($create, $configure);
    }
}
