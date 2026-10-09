<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use LocalAws\Runner\StopSignal;
use PHPUnit\Framework\TestCase;

final class StopSignalTest extends TestCase
{
    public function testStartsNotRequestedAndNotWaiting(): void
    {
        $stop = new StopSignal();

        self::assertFalse($stop->isRequested());
        self::assertFalse($stop->isWaiting());
    }

    public function testARequestWhileWaitingTellsTheCallerToExitNow(): void
    {
        $stop = new StopSignal();
        $stop->setWaiting(true);

        self::assertTrue($stop->request());
        self::assertTrue($stop->isRequested());
    }

    public function testARequestWhileBusyOnlyRemembersIt(): void
    {
        $stop = new StopSignal();

        self::assertFalse($stop->request());
        self::assertTrue($stop->isRequested());
    }

    public function testTheWaitingFlagCanBeClearedAgain(): void
    {
        $stop = new StopSignal();
        $stop->setWaiting(true);
        $stop->setWaiting(false);

        self::assertFalse($stop->request());
    }
}
