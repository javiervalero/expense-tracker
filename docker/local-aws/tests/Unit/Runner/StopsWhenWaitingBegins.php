<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use LocalAws\Runner\StopSignal;

/**
 * A stop request that lands right after the runner marks itself as waiting, before the receive call starts.
 */
final class StopsWhenWaitingBegins extends StopSignal
{
    public function setWaiting(bool $waiting): void
    {
        parent::setWaiting($waiting);
        if ($waiting) {
            $this->request();
        }
    }
}
